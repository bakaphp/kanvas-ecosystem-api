<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\DataTransferObject;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * One document a bank transaction might be settling, how confident we are, and how much of the transaction
 * would be applied to it.
 *
 * `allocationAmount` is NOT always the transaction's full amount:
 *   - a partial payment applies the whole transaction to a document it doesn't fully clear
 *   - a split payment applies one document's balance, with the rest going to sibling candidates
 */
class MatchCandidate
{
    public function __construct(
        public readonly Bill|Invoice $document,
        public readonly float $confidence,
        /** How much of the bank transaction this document takes. */
        public readonly float $allocationAmount,
        /** @var list<string> Human-readable reasons, surfaced to the review queue and the CFO agent. */
        public readonly array $reasons = [],
    ) {
    }

    public function isBill(): bool
    {
        return $this->document instanceof Bill;
    }

    /**
     * True when the allocation reduces the balance without clearing it — the document stays open.
     */
    public function isPartial(): bool
    {
        return $this->allocationAmount < $this->document->balance_due_native - 0.005;
    }

    public function documentNumber(): ?string
    {
        return $this->document instanceof Bill
            ? $this->document->bill_number
            : $this->document->invoice_number;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->isBill() ? 'bill' : 'invoice',
            'id' => $this->document->getId(),
            'number' => $this->documentNumber(),
            'balance_due' => round($this->document->balance_due_native, 2),
            'allocation_amount' => round($this->allocationAmount, 2),
            'is_partial' => $this->isPartial(),
            'confidence' => round($this->confidence, 4),
            'reasons' => $this->reasons,
        ];
    }
}
