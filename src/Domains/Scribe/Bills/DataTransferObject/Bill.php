<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\PayeeInterface;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<BillLine> $lines
 * @property DataCollection<BillTaxLine>|null $taxLines
 */
class Bill extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?PayeeInterface $vendor,
        /** @var DataCollection<BillLine> */
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
        /** @var DataCollection<BillTaxLine>|null */
        public readonly ?DataCollection $taxLines = null,
        public readonly ?string $vendor_display_name = null,
        public readonly ?string $vendor_legal_name = null,
        public readonly ?string $vendor_tax_id = null,
        public readonly ?string $vendor_email = null,
        public readonly ?array $vendor_address_snapshot = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromMultiple(
        AppInterface $app,
        CompanyInterface $company,
        array $input,
    ): self {
        $vendor = null;
        if (! empty($input['vendor_organization_id'])) {
            /** @var Organization $vendor */
            $vendor = Organization::getByIdFromCompanyApp(
                (int) $input['vendor_organization_id'],
                $company,
                $app,
            );
        }

        $lines = new DataCollection(BillLine::class, array_map(
            fn (array $line): BillLine => new BillLine(
                description: (string) $line['description'],
                quantity: (float) ($line['quantity'] ?? 1),
                unit_price_native: (float) $line['unit_price_native'],
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                sku: $line['sku'] ?? null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                discount_rate: isset($line['discount_rate']) ? (float) $line['discount_rate'] : null,
                discount_amount_native: (float) ($line['discount_amount_native'] ?? 0),
                tax_rate: isset($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                expense_account_id: (int) $line['expense_account_id'],
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'] ?? [],
        ));

        return new self(
            app: $app,
            company: $company,
            vendor: $vendor,
            lines: $lines,
            currency: (string) $input['currency'],
            fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
            bill_number: $input['bill_number'] ?? null,
            net_terms_days: isset($input['net_terms_days']) ? (int) $input['net_terms_days'] : null,
            bill_date: isset($input['bill_date']) ? Carbon::parse((string) $input['bill_date']) : null,
            received_date: isset($input['received_date']) ? Carbon::parse((string) $input['received_date']) : null,
            due_date: isset($input['due_date']) ? Carbon::parse((string) $input['due_date']) : null,
            scheduled_payment_date: isset($input['scheduled_payment_date'])
                ? Carbon::parse((string) $input['scheduled_payment_date'])
                : null,
            notes: $input['notes'] ?? null,
            internal_notes: $input['internal_notes'] ?? null,
            terms: $input['terms'] ?? null,
            purchase_order_id: isset($input['purchase_order_id']) ? (int) $input['purchase_order_id'] : null,
            regional_compliance: $input['regional_compliance'] ?? null,
            tax_metadata: $input['tax_metadata'] ?? null,
            metadata: $input['metadata'] ?? null,
            vendor_display_name: $input['vendor_display_name'] ?? null,
            vendor_legal_name: $input['vendor_legal_name'] ?? null,
            vendor_tax_id: $input['vendor_tax_id'] ?? null,
            vendor_email: $input['vendor_email'] ?? null,
            vendor_address_snapshot: $input['vendor_address_snapshot'] ?? null,
        );
    }
}
