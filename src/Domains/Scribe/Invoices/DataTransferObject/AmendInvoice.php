<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Typed payload AmendInvoiceAction consumes.
 *
 * Only the fields legally mutable post-issue. Amounts, billable, currency, fx_rate, status, document_type
 * are intentionally absent — those require credit-note / void+recreate / are immutable snapshot fields.
 *
 * All fields optional; only the ones present in the payload get applied.
 */
class AmendInvoice extends Data
{
    public function __construct(
        public readonly string $reason,
        public readonly ?Carbon $due_date = null,
        public readonly ?Carbon $expected_payment_date = null,
        public readonly ?int $net_terms_days = null,
        public readonly ?string $notes = null,
        public readonly ?string $internal_notes = null,
        public readonly ?string $terms = null,
        public readonly ?array $regional_compliance = null,
        public readonly ?string $external_id = null,
        public readonly ?string $external_url = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
