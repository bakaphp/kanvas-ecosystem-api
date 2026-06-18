<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BillableInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<InvoiceLine> $lines
 * @property DataCollection<InvoiceTaxLine>|null $taxLines
 */
class Invoice extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?BillableInterface $billable,
        /** @var DataCollection<InvoiceLine> */
        public readonly DataCollection $lines,
        public readonly string $currency,
        public readonly float $fx_rate_to_base,
        public readonly DocumentTypeEnum $document_type = DocumentTypeEnum::INVOICE,
        public readonly ?string $invoice_number = null,
        public readonly ?int $net_terms_days = null,
        public readonly ?Carbon $issued_date = null,
        public readonly ?Carbon $due_date = null,
        public readonly ?Carbon $expected_payment_date = null,
        public readonly ?string $notes = null,
        public readonly ?string $internal_notes = null,
        public readonly ?string $terms = null,
        public readonly ?int $quote_id = null,
        public readonly ?int $parent_invoice_id = null,
        public readonly ?array $regional_compliance = null,
        public readonly ?array $tax_metadata = null,
        public readonly ?array $metadata = null,
        public readonly string $tax_calculation_mode = 'exclusive',
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?string $external_url = null,
        public readonly JournalEntryOriginEnum $origin = JournalEntryOriginEnum::KANVAS,
        /** @var DataCollection<InvoiceTaxLine>|null */
        public readonly ?DataCollection $taxLines = null,
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
        $billable = null;
        if (! empty($input['customer_organization_id'])) {
            /** @var Organization $billable */
            $billable = Organization::getByIdFromCompanyApp(
                (int) $input['customer_organization_id'],
                $company,
                $app,
            );
        }

        $lines = new DataCollection(InvoiceLine::class, array_map(
            fn (array $line): InvoiceLine => new InvoiceLine(
                description: $line['description'] ?? null,
                quantity: (float) ($line['quantity'] ?? 1),
                unit_price_native: (float) $line['unit_price_native'],
                discount_amount_native: (float) ($line['discount_amount_native'] ?? 0),
                discount_rate: isset($line['discount_rate']) ? (float) $line['discount_rate'] : null,
                tax_amount_native: (float) ($line['tax_amount_native'] ?? 0),
                tax_rate: isset($line['tax_rate']) ? (float) $line['tax_rate'] : null,
                item_id: isset($line['item_id']) ? (int) $line['item_id'] : null,
                class_id: isset($line['class_id']) ? (int) $line['class_id'] : null,
                department_id: isset($line['department_id']) ? (int) $line['department_id'] : null,
                sort_order: isset($line['sort_order']) ? (int) $line['sort_order'] : null,
                metadata: $line['metadata'] ?? null,
            ),
            $input['lines'] ?? [],
        ));

        return new self(
            app: $app,
            company: $company,
            billable: $billable,
            lines: $lines,
            currency: (string) $input['currency'],
            fx_rate_to_base: (float) ($input['fx_rate_to_base'] ?? 1.0),
            document_type: isset($input['document_type'])
                ? DocumentTypeEnum::from((string) $input['document_type'])
                : DocumentTypeEnum::INVOICE,
            invoice_number: $input['invoice_number'] ?? null,
            net_terms_days: isset($input['net_terms_days']) ? (int) $input['net_terms_days'] : null,
            issued_date: isset($input['issued_date']) ? Carbon::parse((string) $input['issued_date']) : null,
            due_date: isset($input['due_date']) ? Carbon::parse((string) $input['due_date']) : null,
            expected_payment_date: isset($input['expected_payment_date'])
                ? Carbon::parse((string) $input['expected_payment_date'])
                : null,
            notes: $input['notes'] ?? null,
            internal_notes: $input['internal_notes'] ?? null,
            terms: $input['terms'] ?? null,
            quote_id: isset($input['quote_id']) ? (int) $input['quote_id'] : null,
            parent_invoice_id: isset($input['parent_invoice_id']) ? (int) $input['parent_invoice_id'] : null,
            regional_compliance: $input['regional_compliance'] ?? null,
            tax_metadata: $input['tax_metadata'] ?? null,
            metadata: $input['metadata'] ?? null,
        );
    }
}
