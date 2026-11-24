<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Kanvas\Social\Messages\Models\Message;

/**
 * The messages one update folds in, reduced to what the fold needs: the id the model cites, the date an
 * item takes from its evidence, and the line the model reads. Plain data, so the evaluation suite runs
 * the same fold over a recorded transcript without a database.
 */
final readonly class LeadSummaryBatch
{
    /**
     * @param array<int, array{date: string, line: string}> $entries keyed by message id, oldest first
     */
    public function __construct(
        public array $entries,
    ) {
    }

    /**
     * @param list<Message> $messages
     */
    public static function fromMessages(array $messages): self
    {
        $entries = [];

        foreach ($messages as $message) {
            $entries[(int) $message->getId()] = [
                'date' => $message->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
                'line' => LeadConversationMessages::line($message),
            ];
        }

        return new self($entries);
    }

    /**
     * One message as the summary model reads it: its id (what it cites), date and speaker. The
     * evaluation builds lines from recorded transcripts, so the format lives here, not in the reader.
     */
    public static function line(
        int $messageId,
        string $date,
        string $speaker,
        string $text
    ): string {
        return sprintf('#%d [%s] %s: %s', $messageId, $date, $speaker, trim($text));
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function has(int $messageId): bool
    {
        return isset($this->entries[$messageId]);
    }

    public function dateOf(int $messageId): string
    {
        return $this->entries[$messageId]['date'];
    }

    public function highestId(): int
    {
        return max(array_keys($this->entries));
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_column($this->entries, 'line'));
    }
}
