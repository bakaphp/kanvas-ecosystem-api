<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Services;

use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Exceptions\InvalidSalesReceiptTransitionException;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;

/**
 * Trivial state machine — only RECORDED → VOIDED is allowed.
 *
 * Kept as a class (vs inline match) for consistency with InvoiceStateMachine / QuoteStateMachine and to give
 * the future observer a single entry point to attach to.
 */
class SalesReceiptStateMachine
{
    /**
     * @var array<string, list<SalesReceiptStatusEnum>>
     */
    private const ALLOWED = [
        'recorded' => [SalesReceiptStatusEnum::VOIDED],
        'voided' => [],
    ];

    public function assertTransition(SalesReceipt $receipt, SalesReceiptStatusEnum $target): void
    {
        $current = $receipt->status;

        if (! $this->canTransition($current, $target)) {
            throw new InvalidSalesReceiptTransitionException(
                "Sales receipt {$receipt->id} cannot transition status "
                . "from '{$current->value}' to '{$target->value}'."
            );
        }
    }

    public function canTransition(SalesReceiptStatusEnum $from, SalesReceiptStatusEnum $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = self::ALLOWED[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }
}
