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
 * archive() is a deliberate no-op everywhere: a Kanvas store re-derives the active window from the
 * database on every load, and the trimmer is the only cut. ChatHistory::addMessage() computes the
 * number to archive as `count(before) − count(trimmed)`, which a fold in KanvasHistoryTrimmer makes
 * wrong for a real archive; keeping this a no-op is what makes the fold safe. A store that wants
 * archiving (the summarization work) must stop folding first.
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

    public function archive(string $threadId, int $count): void
    {
    }

    public function clear(string $threadId): void
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
        $id = $message->getId();
        $bare = str_starts_with($id, 'msg_') ? substr($id, 4) : $id;

        if (! Str::isUuid($bare)) {
            $bare = (string) Str::uuid7();
        }

        $message->setId($bare);

        return $bare;
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

            $caption = trim((string) ($attachment['caption'] ?? ''));
            $markers[] = $caption !== '' ? "[Attachment: {$caption}]" : '[Attachment]';
        }

        return implode(' ', $markers);
    }
}
