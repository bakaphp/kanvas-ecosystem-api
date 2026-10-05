<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Stores;

use Illuminate\Support\Str;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\PaginatesMessages;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolCall;

/**
 * What every Kanvas store shares. A store is built with its context (app, company, user, entity, lead,
 * media); the thread id reaches every call from the agent instead of the constructor, so a store can be
 * built before its conversation is known.
 *
 * Archiving is by identity, never by count: KanvasChatHistory hands archiveMessages() the ids a trim
 * dropped, and the interface's count-based archive() stays a no-op because a fold makes that count
 * wrong. A store whose rows belong to other writers (Social) keeps both as no-ops and re-derives its
 * window from the database on every load; the conversation store stamps `archived_at`.
 */
abstract class KanvasMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    /**
     * Ids persisted through this instance. ChatHistory already skips a message whose id is loaded; this
     * covers a replay inside one segment, where the history was not reloaded in between.
     *
     * @var array<string, true>
     */
    private array $appended = [];

    final public function append(string $threadId, Message $message): void
    {
        $id = $message->getId();

        if (isset($this->appended[$id])) {
            return;
        }

        $this->appended[$id] = true;
        $this->persist($threadId, $message);
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->paginate($this->loadActive($threadId), $limit, $before);
    }

    final public function archive(string $threadId, int $count): void
    {
    }

    /**
     * @param list<string> $ids
     */
    public function archiveMessages(string $threadId, array $ids): void
    {
    }

    /**
     * When the oldest row still in the model's window was written, as a unix timestamp, or null for a
     * store that cannot say. Memory recall uses it to leave out what the model is already reading.
     */
    public function activeWindowStartedAt(string $threadId): ?int
    {
        return null;
    }

    /**
     * Summarization flushes the thread and re-appends the summary plus the kept tail, so the ids seen
     * so far are forgotten with it or the kept tail would be skipped as already written.
     */
    final public function clear(string $threadId): void
    {
        $this->appended = [];
        $this->archiveAll($threadId);
    }

    protected function archiveAll(string $threadId): void
    {
    }

    abstract protected function persist(string $threadId, Message $message): void;

    /**
     * @param list<ToolCall> $calls
     * @return list<array<string, mixed>>
     */
    protected static function serializeCalls(array $calls): array
    {
        return array_map(static fn (ToolCall $call): array => $call->jsonSerialize(), $calls);
    }

    /**
     * The id a message would have as a row: Neuron prefixes its ids with `msg_`, the columns do not.
     */
    public static function bareId(string $id): string
    {
        return str_starts_with($id, 'msg_') ? substr($id, 4) : $id;
    }

    protected static function isConversationTurn(Message $message): bool
    {
        return in_array($message->getRole(), [MessageRole::USER->value, MessageRole::ASSISTANT->value], true);
    }

    /**
     * Neuron ids are `msg_` plus a UUIDv7; our id columns are the bare UUIDv7. The row takes the uuid
     * and the message takes it back, so the two agree and ChatHistory's same-id dedupe keeps working.
     */
    protected static function rowUuid(Message $message): string
    {
        $bare = self::bareId($message->getId());

        if (! Str::isUuid($bare)) {
            $bare = (string) Str::uuid7();
        }

        $message->setId($bare);

        return $bare;
    }

    protected static function withMarker(string $text, string $marker): string
    {
        return trim($text . ($marker !== '' ? "\n" . $marker : ''));
    }

    /**
     * A "[Attachment: <description>]" memory line from a stored attachment list. Falls back to a bare
     * "[Attachment]" when an attachment exists but its description hasn't been backfilled yet, so an
     * attachment turn is never silently dropped from history.
     *
     * @param list<mixed> $attachments
     */
    protected static function attachmentMarker(array $attachments): string
    {
        $markers = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! array_key_exists('url', $attachment)) {
                continue;
            }

            $markers[] = self::marker($attachment['caption'] ?? null);
        }

        return implode(' ', $markers);
    }

    protected static function marker(mixed $caption): string
    {
        $caption = trim((string) $caption);

        return $caption !== '' ? "[Attachment: {$caption}]" : '[Attachment]';
    }
}
