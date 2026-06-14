<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;

/**
 * Gates Bill document_status transitions.
 *
 *   DRAFT     → RECEIVED   (posts AP JE)
 *   RECEIVED  → PAID       (recompute from allocations — balance hit zero)
 *   RECEIVED  → VOIDED     (posts reversal JE)
 *   PAID      → (terminal — issue a vendor credit instead of voiding)
 *   VOIDED    → (terminal)
 *
 * Same-state transitions (X → X) are allowed for idempotency (re-running Receive on already-received).
 */
class BillStateMachineService
{
    /**
     * @var array<string, list<BillDocumentStatusEnum>>
     */
    private const ALLOWED = [
        'draft' => [
            BillDocumentStatusEnum::RECEIVED,
        ],
        'received' => [
            BillDocumentStatusEnum::PAID,
            BillDocumentStatusEnum::VOIDED,
        ],
        'paid' => [],
        'voided' => [],
    ];

    public function assertTransition(Bill $bill, BillDocumentStatusEnum $target): void
    {
        if (! $this->canTransition($bill->document_status, $target)) {
            throw new InvalidBillTransitionException(
                "Bill {$bill->id} cannot transition document_status "
                . "from '{$bill->document_status->value}' to '{$target->value}'. "
                . 'Allowed: ' . $this->formatAllowed($bill->document_status) . '.'
            );
        }
    }

    public function canTransition(BillDocumentStatusEnum $from, BillDocumentStatusEnum $to): bool
    {
        if ($from === $to) {
            return true;
        }
        $allowed = self::ALLOWED[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    private function formatAllowed(BillDocumentStatusEnum $from): string
    {
        $allowed = self::ALLOWED[$from->value] ?? [];
        if ($allowed === []) {
            return '(none — terminal)';
        }

        return implode(', ', array_map(fn (BillDocumentStatusEnum $e) => $e->value, $allowed));
    }
}
