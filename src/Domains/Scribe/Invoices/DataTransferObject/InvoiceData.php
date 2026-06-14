<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\BillableInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Typed payload CreateInvoiceAction consumes.
 *
 * Per memory rule "Don't queue Spatie Data DTOs with Eloquent models", this DTO holds App + Company + Billable
 * model references. Actions calling it are SYNCHRONOUS (not queued) so the rule doesn't bite — but never put an
 * InvoiceData instance directly on a ShouldQueue job's constructor.
 *
 * @property DataCollection<InvoiceLineData> $lines
 * @property DataCollection<InvoiceTaxLineData>|null $taxLines
 */
class InvoiceData extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?BillableInterface $billable,
        /** @var DataCollection<InvoiceLineData> */
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
        /** @var DataCollection<InvoiceTaxLineData>|null */
        public readonly ?DataCollection $taxLines = null,
    ) {
    }
}
