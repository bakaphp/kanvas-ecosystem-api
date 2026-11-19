<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\PayeeInterface;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Models\Bill as BillModel;
use Kanvas\Scribe\Bills\Models\BillLine as BillLineModel;
use Kanvas\Scribe\Bills\Models\BillTaxLine as BillTaxLineModel;
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
     * The existing bill with only the given changes applied, so UpdateBillAction (which replaces lines and tax
     * lines wholesale) keeps everything else. Changes: bill_number, bill_date, due_date, notes,
     * expense_account_id, subaccount_id (the last two apply to every line). A null change is no change.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function forUpdate(
        BillModel $bill,
        AppInterface $app,
        CompanyInterface $company,
        array $changes,
    ): self {
        $lines = new DataCollection(BillLine::class, $bill->lines->map(
            fn (BillLineModel $line): BillLine => new BillLine(
                description: (string) $line->description,
                quantity: (float) $line->quantity,
                unit_price_native: (float) $line->unit_price_native,
                item_id: $line->item_id !== null ? (int) $line->item_id : null,
                sku: $line->sku,
                sort_order: (int) $line->sort_order,
                discount_rate: $line->discount_rate !== null ? (float) $line->discount_rate : null,
                discount_amount_native: (float) $line->discount_amount_native,
                tax_rate: $line->tax_rate !== null ? (float) $line->tax_rate : null,
                tax_amount_native: (float) $line->tax_amount_native,
                expense_account_id: (int) ($changes['expense_account_id'] ?? $line->expense_account_id),
                subaccount_id: $changes['subaccount_id']
                    ?? ($line->subaccount_id !== null ? (int) $line->subaccount_id : null),
                tax_metadata: $line->tax_metadata,
                class_id: $line->class_id !== null ? (int) $line->class_id : null,
                department_id: $line->department_id !== null ? (int) $line->department_id : null,
                metadata: $line->metadata,
            ),
        )->all());

        $taxLines = new DataCollection(BillTaxLine::class, $bill->taxLines->map(
            fn (BillTaxLineModel $taxLine): BillTaxLine => new BillTaxLine(
                name: (string) $taxLine->name,
                tax_amount_native: (float) $taxLine->tax_amount_native,
                tax_code_id: $taxLine->tax_code_id !== null ? (int) $taxLine->tax_code_id : null,
                tax_rate: $taxLine->tax_rate !== null ? (float) $taxLine->tax_rate : null,
                jurisdiction: $taxLine->jurisdiction,
                metadata: $taxLine->metadata,
            ),
        )->all());

        $billDate = isset($changes['bill_date'])
            ? Carbon::parse((string) $changes['bill_date'])
            : $bill->bill_date;
        $dueDate = isset($changes['due_date'])
            ? Carbon::parse((string) $changes['due_date'])
            : $bill->due_date;

        return new self(
            app: $app,
            company: $company,
            vendor: $bill->vendor,
            lines: $lines,
            currency: (string) $bill->currency,
            fx_rate_to_base: (float) $bill->fx_rate_to_base,
            bill_number: $changes['bill_number'] ?? $bill->bill_number,
            net_terms_days: $bill->net_terms_days !== null ? (int) $bill->net_terms_days : null,
            bill_date: $billDate,
            received_date: $bill->received_date,
            due_date: $dueDate,
            scheduled_payment_date: $bill->scheduled_payment_date,
            notes: $changes['notes'] ?? $bill->notes,
            internal_notes: $bill->internal_notes,
            terms: $bill->terms,
            purchase_order_id: $bill->purchase_order_id !== null ? (int) $bill->purchase_order_id : null,
            regional_compliance: $bill->regional_compliance,
            tax_metadata: $bill->tax_metadata,
            metadata: $bill->metadata,
            tax_calculation_mode: (string) $bill->tax_calculation_mode,
            taxLines: $taxLines,
            vendor_display_name: $bill->vendor_display_name,
            vendor_legal_name: $bill->vendor_legal_name,
            vendor_tax_id: $bill->vendor_tax_id,
            vendor_email: $bill->vendor_email,
            vendor_address_snapshot: $bill->vendor_address_snapshot,
        );
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
