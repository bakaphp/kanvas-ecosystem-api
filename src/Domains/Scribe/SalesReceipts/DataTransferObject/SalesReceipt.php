<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BillableInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<SalesReceiptLine> $lines
 */
class SalesReceipt extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly BillableInterface $billable,
        /** @var DataCollection<SalesReceiptLine> */
        public readonly DataCollection $lines,
        public readonly Carbon $receipt_date,
        public readonly string $currency,
        public readonly float $fx_rate_to_base,
        public readonly ?int $cash_account_id = null,
        public readonly ?int $payment_method_id = null,
        public readonly ?int $payment_id = null,
        public readonly ?string $receipt_number = null,
        public readonly ?string $notes = null,
        public readonly ?string $internal_notes = null,
        public readonly ?array $regional_compliance = null,
        public readonly ?array $tax_metadata = null,
        public readonly ?array $metadata = null,
        public readonly string $tax_calculation_mode = 'exclusive',
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?string $external_url = null,
        public readonly JournalEntryOriginEnum $origin = JournalEntryOriginEnum::KANVAS,
    ) {
    }
}
