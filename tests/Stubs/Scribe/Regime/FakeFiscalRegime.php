<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe\Regime;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalNumberingInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalDocumentInterface;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\AuthorizedRangeNumbering;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Users\Models\Users;
use LogicException;

/**
 * A second country that shares nothing with the DR but the platform: its own types, series, number width, payload
 * and authority. If issuing works for it, no shared code assumes the DR.
 */
final class FakeFiscalRegime implements FiscalRegimeInterface
{
    public const string COUNTRY = 'ZZ';
    public const string ISSUER_TAX_ID = 'ZZ-ISSUER-1';

    /** @var list<array{document: int, type: string}> what assertMayIssue() was asked */
    public static array $checks = [];

    public function country(): string
    {
        return self::COUNTRY;
    }

    public function documentTypes(): array
    {
        return FakeDocumentTypeEnum::cases();
    }

    public function documentType(string $code): ?FakeDocumentTypeEnum
    {
        return FakeDocumentTypeEnum::tryFrom(trim($code));
    }

    public function authority(): string
    {
        return 'ZZTA';
    }

    public function readinessDocumentTypes(): array
    {
        return [FakeDocumentTypeEnum::INVOICE];
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function officialRateSource(): ?OfficialRateSource
    {
        return null;
    }

    public function salesTaxAccountSubType(): ?AccountSubTypeEnum
    {
        return null;
    }

    public function inputTaxAccountSubType(): ?AccountSubTypeEnum
    {
        return null;
    }

    public function taxIdTypes(): array
    {
        return [TaxIdTypeEnum::OTHER];
    }

    public function issuerTaxId(CompanyInterface $company): ?string
    {
        return self::ISSUER_TAX_ID;
    }

    public function salesDocumentType(Invoice $invoice): FakeDocumentTypeEnum
    {
        return match (true) {
            $invoice->isCreditNote() => FakeDocumentTypeEnum::CREDIT_NOTE,
            $invoice->isDebitNote() => FakeDocumentTypeEnum::DEBIT_NOTE,
            default => FakeDocumentTypeEnum::INVOICE,
        };
    }

    public function noteDocumentType(bool $isDebit): FakeDocumentTypeEnum
    {
        return $isDebit ? FakeDocumentTypeEnum::DEBIT_NOTE : FakeDocumentTypeEnum::CREDIT_NOTE;
    }

    public function purchaseDocumentTypes(Bill|Expense $document): array
    {
        return [];
    }

    public function assertMayIssue(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): void
    {
        self::$checks[] = ['document' => $document->getId(), 'type' => $type->code()];
    }

    public function numbering(): FiscalNumberingInterface
    {
        return new AuthorizedRangeNumbering();
    }

    public function buildPayload(
        Invoice|Bill|Expense $document,
        FiscalDocumentTypeInterface $type,
        string $number,
        ?string $expiresAt,
        ?string $systemRef = null,
    ): FakePayload {
        if (! $document instanceof Invoice || ! $type instanceof FakeDocumentTypeEnum) {
            throw new LogicException('The test regime only issues invoices of its own types.');
        }

        $parent = $document->document_type->isNote() ? $document->parentInvoice : null;

        return new FakePayload(
            type: $type,
            number: $number,
            issueDate: Carbon::parse($document->issued_date ?? Carbon::today())->toDateString(),
            systemRef: (string) $systemRef,
            total: (float) $document->total_native,
            currency: strtoupper((string) $document->currency),
            reference: $parent !== null
                ? new EcfReference(encf: (string) $parent->invoice_number, issueDate: Carbon::parse($parent->issued_date)->toDateString(), modificationCode: 1)
                : null,
        );
    }

    public function assertValid(FiscalPayloadInterface $payload): void
    {
        if (! $payload instanceof FakePayload || $payload->total <= 0) {
            throw new ValidationException('The test authority refuses an empty document.');
        }
    }

    public function issuedAttributes(
        FiscalPayloadInterface $payload,
        CompanyInterface $company,
        ?string $provider,
        string $environment,
        array $regimeData,
    ): array {
        $issuedAt = Carbon::parse($payload->issueDate());

        return [
            'country' => self::COUNTRY,
            'provider' => $provider,
            'environment' => $environment,
            'schema_version' => 'test-1',
            'document_type' => $payload->documentType()->code(),
            'series' => $payload->documentType()->series(),
            'number' => $payload->number(),
            'issuer_tax_id' => self::ISSUER_TAX_ID,
            'identity_key' => self::ISSUER_TAX_ID . '|' . $payload->number(),
            'status' => FiscalDocumentStatusEnum::DRAFT,
            'issued_at' => $issuedAt,
            'retain_until' => $issuedAt->copy()->addYears(5),
            'regime_data' => $regimeData,
            ...$this->snapshot($payload),
        ];
    }

    public function snapshot(FiscalPayloadInterface $payload): array
    {
        $total = $payload instanceof FakePayload ? $payload->total : 0.0;

        return [
            'currency' => $payload instanceof FakePayload ? $payload->currency : 'USD',
            'exchange_rate' => 1.0,
            'taxable_total' => $total,
            'exempt_total' => 0.0,
            'tax_total' => 0.0,
            'total' => $total,
        ];
    }

    public function receivedAttributes(
        ReceivedFiscalDocumentInterface $received,
        CompanyInterface $company,
        string $environment
    ): array {
        $issuedAt = Carbon::parse($received->issueDate());

        return [
            'country' => self::COUNTRY,
            'provider' => $received->provider(),
            'environment' => $environment,
            'schema_version' => 'test-1',
            'document_type' => $received->documentType()->code(),
            'series' => $received->documentType()->series(),
            'number' => $received->number(),
            'issuer_tax_id' => $received->vendorTaxId(),
            'receiver_tax_id' => self::ISSUER_TAX_ID,
            'identity_key' => $received->vendorTaxId() . '|' . $received->number(),
            'authority_id' => $received->number(),
            'status' => FiscalDocumentStatusEnum::ACCEPTED,
            'issued_at' => $issuedAt,
            'retain_until' => $issuedAt->copy()->addYears(5),
            'currency' => strtoupper($received->currency()),
            'exchange_rate' => $received->exchangeRate(),
            'total' => (float) ($received->totalsSummary()['total'] ?? 0.0),
            'regime_data' => ['vendor_name' => $received->vendorName()],
        ];
    }

    public function sealFromStamp(string $stampUrl): ?string
    {
        return null;
    }

    public function renderInvoicePdf(Invoice $invoice, Users $user): Filesystem
    {
        throw new LogicException('The test regime renders no PDF.');
    }
}
