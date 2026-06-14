<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\PayeeInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Typed payload CreateBillAction consumes.
 *
 * Vendor is optional at CreateBill (drafts can be entered before the vendor record exists in Guild) —
 * the vendor snapshot is frozen at Receive time, like Invoices' billable snapshot is frozen at Issue.
 *
 * @property DataCollection<BillLineData> $lines
 * @property DataCollection<BillTaxLineData>|null $taxLines
 */
class BillData extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?PayeeInterface $vendor,
        /** @var DataCollection<BillLineData> */
        public readonly DataCollection $lines,
        public readonly string $currency,
        public readonly float $fx_rate_to_base,
        public readonly ?string $bill_number = null,
        public readonly ?int $net_terms_days = null,
        public readonly ?Carbon $bill_date = null,
        public readonly ?Carbon $received_date = null,
        public readonly ?Carbon $due_date = null,
        public readonly ?Carbon $scheduled_payment_date = null,
        public readonly ?string $notes = null,
        public readonly ?string $internal_notes = null,
        public readonly ?string $terms = null,
        public readonly ?int $purchase_order_id = null,
        public readonly ?int $pdf_ingest_log_id = null,
        public readonly ?array $regional_compliance = null,
        public readonly ?array $tax_metadata = null,
        public readonly ?array $metadata = null,
        public readonly string $tax_calculation_mode = 'exclusive',
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?string $external_url = null,
        public readonly JournalEntryOriginEnum $origin = JournalEntryOriginEnum::KANVAS,
        /** @var DataCollection<BillTaxLineData>|null */
        public readonly ?DataCollection $taxLines = null,
    ) {
    }
}
