<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Enums;

/**
 * Quote lifecycle status (per plan §11.1 — quotes are pre-economic-event; no JE posts).
 *
 * Transitions managed by QuoteStateMachineService. Direct status mutation is banned (code-review rule for now;
 * observer enforcement comes with the wider state-machine observer).
 *
 *   draft → sent → accepted | rejected | expired
 *   accepted → converted   (terminal — set when ConvertQuoteToInvoiceAction fires)
 *
 *   Any non-terminal state → superseded   (set when CreateQuoteRevisionAction creates the next revision)
 *
 * @see plan §7.1 status pattern (mirrored from invoices, simpler because there's no collection_state)
 * @see plan §11.1 worked example — quote → revision → accept → convert
 */
enum QuoteStatusEnum: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case CONVERTED = 'converted';
    case SUPERSEDED = 'superseded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CONVERTED, self::REJECTED, self::EXPIRED, self::SUPERSEDED => true,
            default => false,
        };
    }

    public function isMutable(): bool
    {
        // Only drafts can be edited freely.
        return $this === self::DRAFT;
    }

    /**
     * Quotes in these states can still be revised (the parent gets superseded).
     * Already-converted / rejected / expired / superseded quotes cannot be revised — issue a new quote instead.
     */
    public function canBeRevised(): bool
    {
        return match ($this) {
            self::DRAFT, self::SENT => true,
            default => false,
        };
    }
}
