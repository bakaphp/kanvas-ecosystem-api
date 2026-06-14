<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Services;

use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * Gates every status transition on Quote.
 *
 * Allowed graph (mirrors plan §11.1):
 *
 *   DRAFT      → SENT      | SUPERSEDED
 *   SENT       → ACCEPTED  | REJECTED | EXPIRED | SUPERSEDED
 *   ACCEPTED   → CONVERTED
 *   REJECTED   → (terminal)
 *   EXPIRED    → (terminal)
 *   CONVERTED  → (terminal)
 *   SUPERSEDED → (terminal — a revision replaced it)
 *
 * SUPERSEDED is set by CreateQuoteRevisionAction on the parent when a new revision is created.
 */
class QuoteStateMachine
{
    /**
     * @var array<string, list<QuoteStatusEnum>>
     */
    private const ALLOWED = [
        'draft' => [
            QuoteStatusEnum::SENT,
            QuoteStatusEnum::SUPERSEDED,
        ],
        'sent' => [
            QuoteStatusEnum::ACCEPTED,
            QuoteStatusEnum::REJECTED,
            QuoteStatusEnum::EXPIRED,
            QuoteStatusEnum::SUPERSEDED,
        ],
        'accepted' => [
            QuoteStatusEnum::CONVERTED,
        ],
        // Terminal states have no outgoing transitions.
        'rejected' => [],
        'expired' => [],
        'converted' => [],
        'superseded' => [],
    ];

    public function assertTransition(Quote $quote, QuoteStatusEnum $target): void
    {
        $current = $quote->status;

        if (! $this->canTransition($current, $target)) {
            throw new InvalidQuoteTransitionException(
                "Quote {$quote->id} cannot transition status "
                . "from '{$current->value}' to '{$target->value}'. "
                . 'Allowed targets: ' . $this->formatAllowed($current) . '.'
            );
        }
    }

    public function canTransition(QuoteStatusEnum $from, QuoteStatusEnum $to): bool
    {
        if ($from === $to) {
            return true;       // idempotent no-op
        }

        $allowed = self::ALLOWED[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    private function formatAllowed(QuoteStatusEnum $from): string
    {
        $allowed = self::ALLOWED[$from->value] ?? [];
        if ($allowed === []) {
            return '(none — terminal state)';
        }

        return implode(', ', array_map(fn (QuoteStatusEnum $e) => $e->value, $allowed));
    }
}
