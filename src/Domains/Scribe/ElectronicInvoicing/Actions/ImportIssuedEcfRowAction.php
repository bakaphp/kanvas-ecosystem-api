<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfExportRow;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\IssuedEcfRowImportResult as Result;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\ImportInvoiceFromExternalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * One e-CF the provider issued outside Kanvas becomes an ISSUED invoice with its fiscal row and its journal entry
 * in the invoice's own period: Kanvas is the ledger from launch, so the sale has to be on the books, not only on
 * the aging. The export carries no payment state, so every invoice lands unpaid for the bank match to settle.
 *
 * A rejected number is recorded too, as a VOIDED invoice with a `rejected` fiscal row and no journal entry: the
 * authority counts the number as used, and the range check reads fiscal rows.
 *
 * Idempotent on the fiscal identity (issuer + e-NCF): a number already on a fiscal row is skipped, so the same
 * export can be fed twice. The customer is found by tax id, then by name (writing the tax id onto a match that
 * has none), else created; a name match that carries a different tax id is reported, never guessed.
 */
class ImportIssuedEcfRowAction
{
    public const string PROVIDER = 'webpos';
    public const string LEDGER_EVENT = 'scribe.invoice.imported';
    /** `invoices.source` is an enum of accounting systems; a portal export brought in by hand is `manual`, the provider rides on the fiscal row. */
    private const string INVOICE_SOURCE = 'manual';
    private const string VOID_REASON = 'dgii_rejected';

    private ?FiscalRegimeInterface $regime = null;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly IssuedEcfExportRow $row,
        public readonly Users $user,
        public readonly bool $dryRun = false,
    ) {
    }

    public function execute(): Result
    {
        $issuer = DrFiscalRegime::issuerTaxIdFor($this->company) ?? 'company-' . $this->company->getId();
        $identityKey = DrFiscalRegime::identityKey($issuer, $this->row->encf);

        $existing = FiscalDocument::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('identity_key', $identityKey)
            ->first();

        if ($existing !== null) {
            return new Result(
                Result::SKIPPED_EXISTING,
                $this->row,
                "{$this->row->encf} is already on the books.",
                invoiceId: (int) $existing->entity_id,
                fiscalDocumentId: (int) $existing->getKey(),
            );
        }

        $customer = $this->resolveCustomer();

        if ($customer instanceof Result) {
            return $customer;
        }

        [$organization, $organizationAction] = $customer;

        if ($this->dryRun) {
            return new Result(
                $this->row->isRejected() ? Result::REJECTED_RECORDED : Result::CREATED,
                $this->row,
                'Dry run: nothing written.',
                organizationId: $organization?->getId(),
                organizationAction: $organizationAction,
            );
        }

        try {
            [$invoice, $fiscal] = DB::connection('accounting')->transaction(
                fn (): array => $this->import($organization, $issuer, $identityKey)
            );
        } catch (Throwable $e) {
            report($e);

            return new Result(
                Result::FAILED,
                $this->row,
                $e->getMessage(),
                organizationId: $organization?->getId(),
            );
        }

        return new Result(
            $this->row->isRejected() ? Result::REJECTED_RECORDED : Result::CREATED,
            $this->row,
            $this->row->isRejected()
                ? "{$this->row->encf} was rejected by the authority: recorded as voided, the number is burned."
                : "{$this->row->encf} imported as issued, {$this->row->currency} {$this->row->total}.",
            invoiceId: $invoice->getId(),
            fiscalDocumentId: (int) $fiscal->getKey(),
            organizationId: $organization?->getId(),
            organizationAction: $organizationAction,
        );
    }

    /**
     * @return array{0: Invoice, 1: FiscalDocument}
     */
    private function import(?Organization $organization, string $issuer, string $identityKey): array
    {
        $data = $this->invoiceData($organization);

        if ($this->row->isRejected()) {
            // VoidInvoiceAction reverses a posted journal entry; a number the authority rejected never had one.
            $invoice = new CreateInvoiceAction($data, $this->user)->execute();
            $invoice->document_status = InvoiceDocumentStatusEnum::VOIDED;
            $invoice->collection_state = null;
            $invoice->voided_at = $this->row->authorizedAt ?? $this->row->issuedAt;
            $invoice->void_reason_code = self::VOID_REASON;
            $invoice->internal_notes = 'Rejected by the tax authority in the provider; imported as voided. Number burned.';
            $invoice->saveOrFail();
        } else {
            $invoice = new ImportInvoiceFromExternalAction($data, 0.0, $this->user)->execute();
            $invoice->load(['taxLines']);

            new PostJournalEntryAction(
                data: new InvoiceJournalEntryComposerService()->composeIssue($invoice),
                postedByUser: $this->user,
            )->execute();
        }

        $fiscal = new CreateFiscalDocumentAction(
            entity: $invoice,
            direction: FiscalDirectionEnum::ISSUED,
            attributes: $this->fiscalAttributes($invoice, $issuer, $identityKey),
        )->execute();

        $invoice->emitLedgerEvent(
            eventType: self::LEDGER_EVENT,
            payload: [
                'invoice_number' => $invoice->invoice_number,
                'encf' => $this->row->encf,
                'provider' => self::PROVIDER,
                'fiscal_status' => $this->row->status->value,
                'document_status' => $invoice->document_status->value,
                'currency' => $invoice->currency,
                'total_native' => (float) $invoice->total_native,
                'issued_date' => $this->row->issuedAt->toDateString(),
            ],
        );

        return [$invoice->refresh(), $fiscal];
    }

    private function invoiceData(?Organization $organization): InvoiceData
    {
        $line = new InvoiceLineData(
            description: 'Factura ' . $this->row->encf . ($this->row->reference !== null ? " ({$this->row->reference})" : ''),
            quantity: 1.0,
            unit_price_native: $this->row->subtotal,
            tax_amount_native: $this->row->tax,
        );

        return new InvoiceData(
            app: $this->app,
            company: $this->company,
            billable: $organization,
            lines: new DataCollection(InvoiceLineData::class, [$line]),
            currency: $this->row->currency,
            fx_rate_to_base: $this->row->rate,
            invoice_number: $this->row->encf,
            net_terms_days: 0,
            issued_date: $this->row->issuedAt->copy(),
            due_date: $this->row->issuedAt->copy(),
            notes: 'Imported from the provider\'s issued-documents export.',
            regional_compliance: [EcfCompliance::keyFor($this->regime()->country()) => ['type' => $this->row->documentTypeCode()]],
            metadata: [
                'import' => array_filter([
                    'source' => self::PROVIDER . '_export',
                    'provider_ref' => $this->row->providerRef,
                    'track_id' => $this->row->trackId,
                    'authority_status' => $this->row->authorityStatus,
                    'row' => $this->row->rowNumber,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            source: self::INVOICE_SOURCE,
            external_id: $this->row->encf,
            billable_display_name: $this->row->customerName,
            billable_tax_id: $this->row->customerTaxId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fiscalAttributes(Invoice $invoice, string $issuer, string $identityKey): array
    {
        $accepted = $this->row->status->isAccepted();

        return [
            'country' => $this->regime()->country(),
            'provider' => self::PROVIDER,
            'environment' => $this->row->environment->value,
            'schema_version' => DrFiscalRegime::SCHEMA_VERSION,
            'document_type' => $this->row->documentTypeCode(),
            'series' => substr($this->row->encf, 0, 3),
            'number' => $this->row->encf,
            'issuer_tax_id' => $issuer,
            'receiver_tax_id' => $this->row->customerTaxId,
            'identity_key' => $identityKey,
            'authority_id' => $accepted ? $this->row->encf : null,
            'status' => $this->row->status,
            'authority_status' => $this->row->authorityStatus,
            'authority_number_consumed' => true,
            // The portal collapses a conditional acceptance to one word; the observations behind it are not in the export.
            'needs_review' => $this->row->status === FiscalDocumentStatusEnum::ACCEPTED_WITH_OBSERVATIONS,
            'last_authority_update_at' => $this->row->authorizedAt,
            'issued_at' => $this->row->issuedAt,
            'authorized_at' => $accepted ? $this->row->authorizedAt : null,
            'frozen_at' => Carbon::now(),
            'retain_until' => $this->row->issuedAt->copy()->addYears(DrFiscalRegime::RETENTION_YEARS),
            'currency' => $this->row->currency,
            'exchange_rate' => $this->row->rate,
            'taxable_total' => $this->row->subtotal,
            'tax_total' => $this->row->tax,
            'total' => $this->row->total,
            'regime_data' => [
                'type' => $this->row->documentTypeCode(),
                'issue_date' => $this->row->issuedAt->toDateString(),
                'imported_from' => self::PROVIDER . '_export',
                'reference' => $this->row->reference,
            ],
            'authority_data' => array_filter([
                'track_id' => $this->row->trackId,
                'provider_ref' => $this->row->providerRef,
                'imported_invoice_id' => $invoice->getId(),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    private function regime(): FiscalRegimeInterface
    {
        return $this->regime ??= FiscalRegimes::requireForCompany($this->company);
    }

    /**
     * The customer, with how it was found, or the review the row needs instead.
     *
     * @return array{0: Organization|null, 1: string}|Result
     */
    private function resolveCustomer(): array|Result
    {
        $taxId = $this->row->customerTaxId;

        if ($taxId !== null) {
            $byTaxId = Organization::findByTaxId($this->app, $this->company, $taxId);

            if ($byTaxId !== null) {
                return [$byTaxId, 'matched_tax_id'];
            }
        }

        $match = OrganizationVendorMatcherService::match($this->app, $this->company, $this->row->customerName);

        if ($match->candidates !== []) {
            return new Result(
                Result::NEEDS_REVIEW,
                $this->row,
                "\"{$this->row->customerName}\" matches more than one customer: "
                    . implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates))
                    . '. Pick one, give it the tax id, and import again.',
            );
        }

        $organization = $match->organization;

        if ($organization !== null) {
            $existingTaxId = Str::normalizeTaxId($organization->tax_id);

            if ($existingTaxId !== null && $taxId !== null && $existingTaxId !== $taxId) {
                return new Result(
                    Result::NEEDS_REVIEW,
                    $this->row,
                    "\"{$organization->name}\" already carries tax id {$existingTaxId}, the export says {$taxId}. "
                        . 'Settle which is right before importing this one.',
                    organizationId: $organization->getId(),
                );
            }

            if ($existingTaxId === null && $taxId !== null) {
                if (! $this->dryRun) {
                    $organization->tax_id = $taxId;
                    $organization->saveOrFail();
                }

                return [$organization, 'tax_id_written'];
            }

            return [$organization, 'matched_name'];
        }

        if ($this->dryRun) {
            return [null, 'would_create'];
        }

        $created = new CreateOrganizationAction(new OrganizationData(
            company: $this->company,
            user: $this->user,
            app: $this->app,
            name: $this->row->customerName,
            runWorkflow: false,
            taxId: $taxId,
            taxIdType: $taxId !== null
                ? TaxIdTypeEnum::infer($this->regime()->country(), $taxId)
                : null,
        ))->execute();

        return [$created, 'created'];
    }
}
