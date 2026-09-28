<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\ChatHistory\RebuildsTrimmedHistory;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\ModelContextWindowService;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use Override;

class KanvasMessageHistory extends AbstractChatHistory
{
    use RebuildsTrimmedHistory;

    private const string CONNECTION = 'intelligence';
    private const string TABLE_CONVERSATIONS = 'agent_conversations';
    private const string TABLE_MESSAGES = 'agent_conversation_messages';

    /**
     * A memory backstop, not a context limit: applyLoadedHistory() cuts to the token window, and this
     * only bounds how many longtext rows are hydrated to get there. Deliberately far above what any
     * window can hold, so the token trim is always what decides — a row cap that bound first would
     * silently shorten memory on a wide model.
     */
    private const int MAX_LOADED_ROWS = 1_000;

    /**
     * @param list<string> $turnMedia Current turn's attachment URLs (image/audio/PDF), captured on
     *                                the user message so a later text-only history rebuild can still
     *                                remember the attachment.
     */
    private ?string $conversationId = null;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $agentClass,
        private readonly ?string $sessionId = null,
        int $contextWindow = ModelContextWindowService::MIN_HISTORY_TOKENS,
        private readonly ?Agent $agent = null,
        private readonly array $turnMedia = [],
        private readonly ?string $model = null,
        private readonly bool $privateUserTurn = false,
    ) {
        parent::__construct($contextWindow);

        // Key the conversation on the session (one thread per session+agent), not "latest by user"
        // which can glom onto an unrelated conversation. Falls back to latest only when there's no
        // session id at all (ad-hoc invocations).
        $this->conversationId = $this->sessionId !== null
            ? new KanvasConversationStore()->conversationForSession(
                $this->user->getId(),
                $this->sessionId,
                $this->agent?->getId(),
                $this->app->getId(),
                $this->company->getId(),
            )
            : $this->findLatestConversation();

        if ($this->conversationId !== null) {
            $this->load();
        }
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    private function findLatestConversation(): ?string
    {
        return DB::connection(self::CONNECTION)
            ->table(self::TABLE_CONVERSATIONS)
            ->where('user_id', $this->user->getId())
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    private function load(): void
    {
        $messages = DB::connection(self::CONNECTION)
            ->table(self::TABLE_MESSAGES)
            ->where('conversation_id', $this->conversationId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::MAX_LOADED_ROWS)
            // The rebuild is text-only; tool_calls/tool_results/meta/usage are longtext on every row
            // and were being hydrated and decoded for nothing.
            ->get(['role', 'content', 'attachments'])
            ->reverse()
            ->map(function ($row): ?Message {
                $content = (string) ($row->content ?? '');
                $attachmentMarker = $this->buildAttachmentMarker($row->attachments ?? null);

                // An attachment-only turn (e.g. a photo with no caption) must not vanish from history.
                if ($content === '' && $attachmentMarker === '') {
                    return null;
                }

                $content = trim($content . ($attachmentMarker !== '' ? "\n" . $attachmentMarker : ''));

                return $row->role === MessageRole::ASSISTANT->value
                    ? new AssistantMessage($content)
                    : new UserMessage($content);
            })
            ->filter()
            ->values()
            ->all();

        $this->applyLoadedHistory($messages);
    }

    #[Override]
    public function addMessage(Message $message): ChatHistoryInterface
    {
        return $this->mergeOrAppend($message);
    }

    #[Override]
    protected function onNewMessage(Message $message): void
    {
        $role = $message->getRole();

        if (! in_array($role, [MessageRole::USER->value, MessageRole::ASSISTANT->value], true)) {
            return;
        }

        $content = (string) ($message->getContent() ?? '');
        $isToolCall = $message instanceof ToolCallMessage;
        $isToolResult = $message instanceof ToolResultMessage;

        if ($content === '' && ! $isToolCall && ! $isToolResult) {
            return;
        }

        if ($this->conversationId === null) {
            $this->conversationId = new KanvasConversationStore()->insertConversation(
                $this->user->getId(),
                $this->agent?->getId(),
                $this->app->getId(),
                $this->company->getId(),
                Str::limit($content !== '' ? $content : '[tool call]', 100, ''),
            );
        } else {
            DB::connection(self::CONNECTION)
                ->table(self::TABLE_CONVERSATIONS)
                ->where('id', $this->conversationId)
                ->update(['updated_at' => now()]);
        }

        $toolCalls = $message instanceof ToolCallMessage
            ? array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $message->getTools())
            : [];

        $toolResults = $message instanceof ToolResultMessage
            ? array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $message->getTools())
            : [];

        $usage = $message->getUsage()?->jsonSerialize() ?? [];
        // Carry the model on assistant turns so the usage rollup can price them — Neuron doesn't
        // put the model on the message, and logTurn (which used to add it) no longer runs here.
        if ($role === MessageRole::ASSISTANT->value && $this->model !== null && ! isset($usage['model'])) {
            $usage['model'] = $this->model;
        }

        // A private turn (e.g. an injected agent-task wake instruction) is persisted is_public=0 so the
        // chat UI hides it. Only the user turn is hidden — the agent's own reply stays visible.
        $isPublic = $this->privateUserTurn && $role === MessageRole::USER->value ? 0 : 1;

        $meta = $message->jsonSerialize();
        unset($meta['role'], $meta['content'], $meta['usage'], $meta['tools']);

        // Only the user turn carries the prompt's attachments; persist a reference so the describe
        // backfill (and any later rebuild) can recover them — the stored row is text-only.
        $isUserTurn = $role === MessageRole::USER->value;
        $turnMedia = $isUserTurn ? $this->turnMedia : [];
        $attachments = array_map(
            static fn (string $url): array => ['url' => $url, 'caption' => ''],
            array_values($turnMedia),
        );

        $messageId = (string) Str::uuid7();

        DB::connection(self::CONNECTION)->table(self::TABLE_MESSAGES)->insert([
            'id' => $messageId,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->user->getId(),
            'agent' => $this->agentClass,
            'role' => $role,
            'is_public' => $isPublic,
            'content' => $content,
            'attachments' => json_encode($attachments),
            'tool_calls' => json_encode($toolCalls),
            'tool_results' => json_encode($toolResults),
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
     * Build a "[Attachment: <description>]" memory line from the row's stored attachments. Falls
     * back to a bare "[Attachment]" when an attachment exists but its description hasn't been
     * backfilled yet, so an attachment turn is never silently dropped from history.
     */
    private function buildAttachmentMarker(?string $attachmentsJson): string
    {
        if ($attachmentsJson === null || $attachmentsJson === '' || $attachmentsJson === '[]') {
            return '';
        }

        $decoded = json_decode($attachmentsJson, true);

        if (! is_array($decoded) || $decoded === []) {
            return '';
        }

        $markers = [];
        foreach ($decoded as $attachment) {
            if (! is_array($attachment) || ! array_key_exists('url', $attachment)) {
                continue;
            }
            $caption = trim((string) ($attachment['caption'] ?? ''));
            $markers[] = $caption !== '' ? "[Attachment: {$caption}]" : '[Attachment]';
        }

        return implode(' ', $markers);
    }
}
