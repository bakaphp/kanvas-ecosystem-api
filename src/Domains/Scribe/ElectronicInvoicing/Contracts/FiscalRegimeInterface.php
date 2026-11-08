<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Users\Models\Users;

/**
 * A country's electronic-invoicing rules, the only place they live. Shared code (issuance, numbering, the fiscal
 * row, the jobs, the agent tools) resolves the regime through FiscalRegimes and asks it; it never names a
 * country's types, payload or authority.
 *
 * A new country implements this in `Regimes/{Country}/`, registers it in `config/scribe.php` `fiscal_regimes`,
 * and ships a provider whose `country()` names it.
 */
interface FiscalRegimeInterface
{
    /**
     * The file field every regime's own legal invoice PDF is attached under.
     */
    public const string INVOICE_PDF_FIELD = 'ecf_invoice_pdf';

    /**
     * ISO 3166-1 alpha-2, as stored in `fiscal_documents.country`.
     */
    public function country(): string;

    /**
     * @return list<FiscalDocumentTypeInterface>
     */
    public function documentTypes(): array;

    public function documentType(string $code): ?FiscalDocumentTypeInterface;

    /**
     * The authority's short name, as people say it (DGII, SAT, DIAN).
     */
    public function authority(): string;

    /**
     * The types a company must be able to number before it counts as ready to invoice (DR: E31).
     *
     * @return list<FiscalDocumentTypeInterface>
     */
    public function readinessDocumentTypes(): array;

    /**
     * ISO 4217 code the authority keeps its books in (DOP, MXN, COP; USD where the dollar is legal tender).
     */
    public function currency(): string;

    /**
     * The central bank and rate a foreign-currency document declares; null where the local currency is the dollar
     * and nothing needs converting.
     */
    public function officialRateSource(): ?OfficialRateSource;

    /**
     * The ledger sub-types a tax line of this jurisdiction posts to when the company's chart has them (DR: the ITBIS
     * accounts); null keeps the generic sales-tax payable / input-tax receivable.
     */
    public function salesTaxAccountSubType(): ?AccountSubTypeEnum;

    public function inputTaxAccountSubType(): ?AccountSubTypeEnum;

    /**
     * The tax ids the authority's own registry holds. A buyer whose id is of another type is foreign.
     *
     * @return list<TaxIdTypeEnum>
     */
    public function taxIdTypes(): array;

    /**
     * The issuer's registered tax id, or null when the company has none configured.
     */
    public function issuerTaxId(CompanyInterface $company): ?string;

    /**
     * Which document a sale becomes: an invoice, or a credit / debit note on one.
     */
    public function salesDocumentType(Invoice $invoice): FiscalDocumentTypeInterface;

    public function noteDocumentType(bool $isDebit): FiscalDocumentTypeInterface;

    /**
     * The documents the company may issue itself for what it buys (DR E41/E43/E47); empty where the authority has
     * none, which refuses the request.
     *
     * @return list<FiscalDocumentTypeInterface>
     */
    public function purchaseDocumentTypes(Bill|Expense $document): array;

    /**
     * Everything the authority would refuse that can be checked before a number is spent and that may need the
     * network (a buyer's registration). Runs outside the issue transaction.
     */
    public function assertMayIssue(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): void;

    public function numbering(): FiscalNumberingInterface;

    /**
     * The wire document; `$number` is empty while it is validated before the number is taken.
     */
    public function buildPayload(
        Invoice|Bill|Expense $document,
        FiscalDocumentTypeInterface $type,
        string $number,
        ?string $expiresAt,
        ?string $systemRef = null,
    ): FiscalPayloadInterface;

    public function assertValid(FiscalPayloadInterface $payload): void;

    /**
     * The new `fiscal_documents` row for an issued document: identity, routing and the money snapshot.
     *
     * @param array<string, mixed> $regimeData
     * @return array<string, mixed>
     */
    public function issuedAttributes(
        FiscalPayloadInterface $payload,
        CompanyInterface $company,
        ?string $provider,
        string $environment,
        array $regimeData,
    ): array;

    /**
     * The money columns of the row, refreshed from what a send actually carried.
     *
     * @return array<string, mixed>
     */
    public function snapshot(FiscalPayloadInterface $payload): array;

    /**
     * The new `fiscal_documents` row for a document a vendor issued to the company. It reaches us only after the
     * authority accepted it, so the row starts accepted.
     *
     * @return array<string, mixed>
     */
    public function receivedAttributes(
        ReceivedFiscalDocumentInterface $received,
        CompanyInterface $company,
        string $environment
    ): array;

    /**
     * The authority's seal (DR: CodigoSeguridad) carried in its verification URL, when the URL carries one.
     */
    public function sealFromStamp(string $stampUrl): ?string;

    /**
     * Kanvas's own rendering of the authority's printed representation of an accepted invoice, attached under
     * INVOICE_PDF_FIELD.
     */
    public function renderInvoicePdf(Invoice $invoice, Users $user): Filesystem;
}
