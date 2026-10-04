<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
        return DB::connection(self::CONNECTION)
            ->table(self::TABLE_MESSAGES)
            ->where('conversation_id', $this->conversationId($threadId))
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::MAX_LOADED_ROWS)
            ->get(['id', 'role', 'content', 'attachments'])
            ->reverse()
            ->map(function (object $row): ?Message {
                $content = (string) ($row->content ?? '');
                $marker = self::attachmentMarker(self::decodeAttachments($row->attachments ?? null));

                // An attachment-only turn (e.g. a photo with no caption) must not vanish from history.
                if ($content === '' && $marker === '') {
                    return null;
                }

                $content = trim($content . ($marker !== '' ? "\n" . $marker : ''));

                $message = $row->role === MessageRole::ASSISTANT->value
                    ? new AssistantMessage($content)
                    : new UserMessage($content);

                return $message->setId((string) $row->id);
            })
            ->filter()
            ->values()
            ->all();
    }

    #[Override]
    protected function persist(string $threadId, Message $message): void
    {
        if (! self::isConversationTurn($message)) {
            return;
        }

        $role = $message->getRole();
        $content = (string) ($message->getContent() ?? '');
        $isToolCall = $message instanceof ToolCallMessage;
        $isToolResult = $message instanceof ToolResultMessage;

        if ($content === '' && ! $isToolCall && ! $isToolResult) {
            return;
        }

        $conversationId = $this->conversationId($threadId);

        DB::connection(self::CONNECTION)
            ->table(self::TABLE_CONVERSATIONS)
            ->where('id', $conversationId)
            ->update(['updated_at' => now()]);

        $toolCalls = $isToolCall ? self::serializeCalls($message->getToolCalls()) : [];
        $toolResults = $isToolResult ? self::serializeCalls($message->getToolCalls()) : [];

        $usage = $message->getUsage() !== null
            ? ConversationUsageSqlHelper::neuronUsageRow($message->getUsage(), (int) ($message->getMetadata('cacheWriteTokens') ?? 0))
            : [];
        // Carry the model on assistant turns so the usage rollup can price them — Neuron doesn't
        // put the model on the message.
        if ($role === MessageRole::ASSISTANT->value && $this->model !== null && ! isset($usage['model'])) {
            $usage['model'] = $this->model;
        }

        // A private turn (e.g. an injected agent-task wake instruction) is persisted is_public=0 so the
        // chat UI hides it. Only the user turn is hidden — the agent's own reply stays visible.
        $isUserTurn = $role === MessageRole::USER->value;
        $isPublic = $this->privateUserTurn && $isUserTurn ? 0 : 1;

        $messageId = self::rowUuid($message);

        $meta = $message->jsonSerialize();
        unset($meta['__id'], $meta['role'], $meta['content'], $meta['usage'], $meta['tools']);

        // Only the user turn carries the prompt's attachments; persist a reference so the describe
        // backfill (and any later rebuild) can recover them — the stored row is text-only.
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
     * @return list<mixed>
     */
    private static function decodeAttachments(?string $json): array
    {
        if ($json === null || $json === '' || $json === '[]') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
