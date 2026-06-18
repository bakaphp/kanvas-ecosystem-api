<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Services;

use Closure;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * Gates every status transition on Quote (mirrors plan §11.1).
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
class QuoteStateMachineService extends AbstractDocumentStateMachineService
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
        'rejected' => [],
        'expired' => [],
        'converted' => [],
        'superseded' => [],
    ];

    /**
     * @throws InvalidQuoteTransitionException
     */
    public function assertTransition(Quote $quote, QuoteStatusEnum $target): void
    {
        $this->guardTransition(
            entityId: (int) $quote->id,
            current: $quote->status,
            target: $target,
        );
    }

    protected function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    protected function entityLabel(): string
    {
        return 'Quote';
    }

    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidQuoteTransitionException($message);
    }
}
