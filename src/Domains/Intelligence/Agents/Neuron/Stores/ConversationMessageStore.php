<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Stores;

use Baka\Traits\ScalarCoercionTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Helpers\ConversationStepsHelper;
use Kanvas\Intelligence\Agents\Helpers\ConversationUsageSqlHelper;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * The agent's own transcript: one row per turn in `agent_conversation_messages`, keyed to one
 * conversation per session and agent.
 */
class ConversationMessageStore extends KanvasMessageStore
{
    use ScalarCoercionTrait;

    private const string CONNECTION = 'intelligence';
    private const string TABLE_CONVERSATIONS = 'agent_conversations';
    private const string TABLE_MESSAGES = 'agent_conversation_messages';

    /**
     * A memory backstop, not a context limit: the trimmer cuts to the token window, and this only
     * bounds how many longtext rows are hydrated to get there. Deliberately far above what any window
     * can hold, so the token trim is always what decides — a row cap that bound first would silently
     * shorten memory on a wide model.
     */
    private const int MAX_LOADED_ROWS = 1_000;

    /** @var array<string, string> thread id => conversation id */
    private array $conversations = [];

    /**
     * @param string|null $sessionId Keys the conversation when set (one thread per session and agent);
     *                               the thread id is the key otherwise. userChat binds the session uuid
     *                               as the thread, so the two agree there; a channel turn is bound to the
     *                               entity uuid and keeps its session-keyed conversation through this.
     * @param list<string> $turnMedia Current turn's attachment URLs (image/audio/PDF), captured on the
     *                                user message so a later text-only rebuild still remembers them.
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $agentClass,
        private readonly ?string $sessionId = null,
        private readonly ?Agent $agent = null,
        private readonly array $turnMedia = [],
        private readonly ?string $model = null,
        private readonly bool $privateUserTurn = false,
        private readonly ?Model $participant = null,
    ) {
    }

    public function conversationId(string $threadId): string
    {
        return $this->conversations[$threadId] ??= new KanvasConversationStore()->conversationForSession(
            $this->user->getId(),
            $this->sessionId ?? $threadId,
            $this->agent?->getId(),
            $this->app->getId(),
            $this->company->getId(),
            $this->participant,
        );
    }

    /**
     * Text-only on purpose: steps, meta and usage are longtext on every row and the model never sees them.
     *
     * @return list<Message>
     */
    #[Override]
    public function loadActive(string $threadId): array
    {
        return $this->activeRows($threadId)
            ->orderByDesc('sequence')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LOADED_ROWS)
            ->get(['id', 'role', 'content', 'attachments'])
            ->reverse()
            ->map(function (object $row): ?Message {
                $content = (string) ($row->content ?? '');
                $marker = self::attachmentMarker($this->decodeJsonArray($row->attachments ?? null) ?? []);

                if ($content === '' && $marker === '') {
                    return null;
                }

                $content = self::withMarker($content, $marker);

                $message = $row->role === MessageRole::ASSISTANT->value
                    ? new AssistantMessage($content)
                    : new UserMessage($content);

                return $message->setId((string) $row->id);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $ids
     */
    #[Override]
    public function archiveMessages(string $threadId, array $ids): void
    {
        $this->activeRows($threadId)->whereIn('id', $ids)->update(['archived_at' => now()]);
    }

    #[Override]
    protected function archiveAll(string $threadId): void
    {
        $this->activeRows($threadId)->update(['archived_at' => now()]);
    }

    #[Override]
    protected function persist(string $threadId, Message $message): void
    {
        if (! self::isConversationTurn($message)) {
            return;
        }

        $messageId = self::rowUuid($message);
        $conversationId = $this->conversationId($threadId);

        if ($this->reviveArchivedRow($messageId, $conversationId)) {
            return;
        }

        $role = $message->getRole();
        $content = (string) ($message->getContent() ?? '');
        $isToolCall = $message instanceof ToolCallMessage;
        $isToolResult = $message instanceof ToolResultMessage;

        if ($content === '' && ! $isToolCall && ! $isToolResult) {
            return;
        }

        DB::connection(self::CONNECTION)
            ->table(self::TABLE_CONVERSATIONS)
            ->where('id', $conversationId)
            ->update(['updated_at' => now()]);

        $toolCalls = $isToolCall ? self::serializeCalls($message->getToolCalls()) : [];
        $toolResults = $isToolResult ? self::serializeCalls($message->getToolCalls()) : [];

        $usage = ConversationUsageSqlHelper::neuronUsageRow($message);
        // Neuron does not put the model on the message; the spend rollup prices by it.
        if ($role === MessageRole::ASSISTANT->value && $this->model !== null && ! isset($usage['model'])) {
            $usage['model'] = $this->model;
        }

        // A private turn (an injected wake instruction) hides only the user side; the reply stays visible.
        $isUserTurn = $role === MessageRole::USER->value;
        $isPublic = $this->privateUserTurn && $isUserTurn ? 0 : 1;

        $meta = $message->jsonSerialize();
        unset($meta['__id'], $meta['role'], $meta['content'], $meta['usage'], $meta['tools'], $meta['__meta'][KanvasHistoryTrimmer::FOLDED_IDS]);

        $turnMedia = $isUserTurn ? $this->turnMedia : [];
        $attachments = array_map(
            static fn (string $url): array => ['url' => $url, 'caption' => ''],
            array_values($turnMedia),
        );

        [$participantType, $participantId] = KanvasConversationStore::participantColumns($this->participant, $this->user->getId());

        DB::connection(self::CONNECTION)->table(self::TABLE_MESSAGES)->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $this->user->getId(),
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent' => $this->agentClass,
            'role' => $role,
            'is_public' => $isPublic,
            'content' => $content,
            'attachments' => json_encode($attachments),
            'steps' => json_encode(ConversationStepsHelper::forRow(
                $role,
                $content,
                $toolCalls,
                $toolResults,
            )),
            'status' => AgentConversationMessage::STATUS_COMPLETED,
            'sequence' => $this->nextSequence($conversationId),
            'usage' => json_encode($usage),
            'meta' => json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($turnMedia !== [] && $this->agent !== null) {
            DescribeMessageAttachmentsJob::dispatch(
                $this->app,
                $this->agent,
                $this->user,
                CaptionTargetEnum::CONVERSATION_MESSAGE,
                $messageId,
                array_values($turnMedia),
            );
        }
    }

    /**
     * A message whose row exists is one a summary kept: the flush archived it and it comes back active,
     * at the end of the window. The same check keeps a replayed write from inserting a duplicate key.
     */
    private function reviveArchivedRow(string $messageId, string $conversationId): bool
    {
        $rows = DB::connection(self::CONNECTION)->table(self::TABLE_MESSAGES)->where('id', $messageId);

        if (! (clone $rows)->exists()) {
            return false;
        }

        $rows->update([
            'archived_at' => null,
            'sequence' => $this->nextSequence($conversationId),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function nextSequence(string $conversationId): int
    {
        return new KanvasConversationStore()->nextSequence($conversationId);
    }

    private function activeRows(string $threadId): Builder
    {
        return DB::connection(self::CONNECTION)
            ->table(self::TABLE_MESSAGES)
            ->where('conversation_id', $this->conversationId($threadId))
            ->whereNull('archived_at');
    }
}
