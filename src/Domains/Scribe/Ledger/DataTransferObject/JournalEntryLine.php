<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Typed shape of one line on a journal entry. Used by sub-ledger composers
 * (InvoiceJournalEntryComposerService, BillJournalEntryComposerService, etc.) to build the
 * JE payload that flows into PostJournalEntryAction.
 *
 * Exactly one of (debit_native, credit_native) must be non-zero — enforced by
 * JournalEntryValidatorService at write time.
 */
class JournalEntryLine extends Data
{
    public function __construct(
        public readonly int $account_id,
        public readonly float $debit_native,
        public readonly float $credit_native,
        public readonly float $debit_base,
        public readonly float $credit_base,
        public readonly string $currency,
        public readonly float $fx_rate_to_base,
        public readonly ?int $sort_order = null,
        public readonly ?string $customer_billable_type = null,
        public readonly ?int $customer_billable_id = null,
        public readonly ?string $vendor_billable_type = null,
        public readonly ?int $vendor_billable_id = null,
        public readonly ?int $item_id = null,
        public readonly ?int $class_id = null,
        public readonly ?int $department_id = null,
        public readonly ?string $memo = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
