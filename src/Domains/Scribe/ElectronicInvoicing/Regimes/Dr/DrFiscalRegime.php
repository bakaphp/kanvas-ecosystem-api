<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Baka\Contracts\CompanyInterface;
use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalNumberingInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalPayloadInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalDocumentInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\ReceivedEcf;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\ElectronicInvoicing\Services\AuthorizedRangeNumbering;
use Kanvas\Scribe\ElectronicInvoicing\Support\BuyerTaxId;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;
use Kanvas\Scribe\FxRates\Services\FxRateService;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Regional\DataTransferObject\TaxpayerLookup;
use Kanvas\Scribe\Regional\Services\TaxpayerLookupService;
use Kanvas\Users\Models\Users;

/**
 * The Dominican Republic: DGII's e-CF types and how a sale picks one, the RNC checks, e-NCF ranges, the payload and
 * its validation, and the fiscal row's identity and money snapshot at DGII's two-decimal scale.
 */
final class DrFiscalRegime implements FiscalRegimeInterface
{
    public const string COUNTRY = 'DO';
    public const string SCHEMA_VERSION = '1.0';
    public const int RETENTION_YEARS = 10;
    public const string CURRENCY = 'DOP';

    public function country(): string
    {
        return self::COUNTRY;
    }

    public function currency(): string
    {
        return self::CURRENCY;
    }

    /**
     * DGII's technical consultation (October 2025) points to the Banco Central's daily buy rate.
     */
    public function officialRateSource(): OfficialRateSource
    {
        return new OfficialRateSource(bank: 'bcrd', type: 'buy', localCurrency: self::CURRENCY);
    }

    public function documentTypes(): array
    {
        return EcfTypeEnum::cases();
    }

    public function documentType(string $code): ?EcfTypeEnum
    {
        return EcfTypeEnum::parse($code);
    }

    public function authority(): string
    {
        return 'DGII';
    }

    public function readinessDocumentTypes(): array
    {
        return [EcfTypeEnum::CREDITO_FISCAL];
    }

    /**
     * DGII numbers e-NCFs per issuer, so issuer RNC + e-NCF is unique at the authority.
     */
    public static function identityKey(?string $issuerTaxId, ?string $encf): string
    {
        $issuer = Str::trimToNull($issuerTaxId);
        $number = Str::trimToNull(strtoupper((string) $encf));

        if ($issuer === null || $number === null) {
            throw new ValidationException('A DR fiscal identity needs both the issuer RNC and the e-NCF.');
        }

        return "{$issuer}|{$number}";
    }

    public static function issuerTaxIdFor(CompanyInterface $company): ?string
    {
        return Str::trimToNull(Str::digitsOnly((string) $company->get(ConfigurationEnum::ECF_ISSUER_TAX_ID->value)));
    }

    /**
     * DGII requires RncEmisor on every e-CF and numbers e-NCFs per issuer, so an issued row without one would
     * carry an identity the authority does not know.
     */
    public static function requireIssuerTaxId(CompanyInterface $company): string
    {
        return self::issuerTaxIdFor($company) ?? throw new ValidationException(
            "Company {$company->getId()} has no issuer RNC configured, so no e-CF can be issued. "
            . 'Set the company setting ' . ConfigurationEnum::ECF_ISSUER_TAX_ID->value . ' to the RNC DGII authorized for e-CF.'
        );
    }

    public function salesTaxAccountSubType(): AccountSubTypeEnum
    {
        return AccountSubTypeEnum::DR_ITBIS_PAYABLE;
    }

    public function inputTaxAccountSubType(): AccountSubTypeEnum
    {
        return AccountSubTypeEnum::DR_ITBIS_RECEIVABLE;
    }

    public function taxIdTypes(): array
    {
        return [TaxIdTypeEnum::DO_RNC, TaxIdTypeEnum::DO_CEDULA];
    }

    /**
     * The shape of an RNC or cédula, for a number already known to be Dominican. It cannot tell one from a foreign
     * id of the same length: that is BuyerTaxId's job.
     */
    public static function isTaxId(?string $taxId): bool
    {
        return FiscalRegimes::isRegistryTaxId(new self(), $taxId);
    }

    public function issuerTaxId(CompanyInterface $company): ?string
    {
        return self::issuerTaxIdFor($company);
    }

    public function salesDocumentType(Invoice $invoice): EcfTypeEnum
    {
        return new EcfTypeResolver()->resolve($invoice);
    }

    public function noteDocumentType(bool $isDebit): EcfTypeEnum
    {
        return $isDebit ? EcfTypeEnum::NOTA_DEBITO : EcfTypeEnum::NOTA_CREDITO;
    }

    /**
     * E41 for a supplier who issues no e-CF, E43 for a minor expense, E47 for a payment to a foreign payee.
     */
    public function purchaseDocumentTypes(Bill|Expense $document): array
    {
        return $document instanceof Bill
            ? [EcfTypeEnum::COMPRAS, EcfTypeEnum::PAGOS_EXTERIOR]
            : [EcfTypeEnum::GASTOS_MENORES, EcfTypeEnum::PAGOS_EXTERIOR];
    }

    /**
     * DGII requires RncEmisor on every e-CF, and an E31 names a buyer registered at DGII: a number of the right
     * shape DGII does not know (a foreign id saved as an RNC, a typo) is refused before it costs an e-NCF. A failed
     * lookup lets it through, so an outage never stops issuing.
     */
    public function assertMayIssue(Invoice|Bill|Expense $document, FiscalDocumentTypeInterface $type): void
    {
        self::requireIssuerTaxId($document->company);

        if ($document instanceof Invoice && $type === EcfTypeEnum::CONSUMO) {
            $this->assertConsumoMayGoWithoutBuyerTaxId($document);
        }

        if (! $document instanceof Invoice || $type !== EcfTypeEnum::CREDITO_FISCAL) {
            return;
        }

        $taxId = (string) BuyerTaxId::of($document);
        $lookup = new TaxpayerLookupService()->lookup($document->app, $document->company, $taxId);

        if ($lookup->status === TaxpayerLookup::NOT_REGISTERED) {
            throw new ValidationException(
                "DGII has no taxpayer registered under {$taxId}, so invoice {$document->getId()} was not issued. "
                . 'If the customer is foreign, set its tax id type (for example us_ein) and it goes out as E32; '
                . 'otherwise correct the number.'
            );
        }
    }

    /**
     * DGII takes an E32 without the buyer's id only under DOP 250,000, so a foreign customer with no tax id on file
     * is refused here, before an approver signs, instead of by the validator after. The peso value is estimated with
     * today's official rate (the real one is frozen on the issue date); with no rate on file the validator decides.
     */
    private function assertConsumoMayGoWithoutBuyerTaxId(Invoice $invoice): void
    {
        if (BuyerTaxId::of($invoice) !== null) {
            return;
        }

        $currency = strtoupper((string) $invoice->currency);
        $rate = $currency === self::CURRENCY
            ? 1.0
            : (float) (new FxRateService()->rateOn($invoice->app, $currency, self::CURRENCY, Carbon::today())?->rate ?? 0);

        if ($rate <= 0 || (float) $invoice->total_native * $rate < DrEcfValidator::CONSUMER_ANONYMOUS_LIMIT_DOP) {
            return;
        }

        throw new ValidationException(sprintf(
            'Invoice %d is %s %s, about DOP %s, over the DOP %s limit for a consumo invoice (E32) with no buyer tax id, '
            . 'and the customer has none on file. Record the customer\'s tax id first (a US company: its EIN with '
            . 'tax_id_type us_ein), or mark the invoice as an export (E46) if that is what it is.',
            $invoice->getId(),
            number_format((float) $invoice->total_native, 2),
            $currency,
            number_format((float) $invoice->total_native * $rate, 0),
            number_format(DrEcfValidator::CONSUMER_ANONYMOUS_LIMIT_DOP, 0)
        ));
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
    ): EcfDocument {
        return new EcfDocumentBuilder()->buildFor(
            $document,
            type: self::type($type),
            encf: $number,
            expiresAt: $expiresAt ?? '',
            systemRef: $systemRef,
        );
    }

    public function assertValid(FiscalPayloadInterface $payload): void
    {
        new DrEcfValidator()->assertValid(EcfDocument::narrow($payload));
    }

    public function issuedAttributes(
        FiscalPayloadInterface $payload,
        CompanyInterface $company,
        ?string $provider,
        string $environment,
        array $regimeData,
    ): array {
        $document = EcfDocument::narrow($payload);
        $issuer = self::requireIssuerTaxId($company);
        $issuedAt = Carbon::parse($document->issueDate);

        return [
            'country' => self::COUNTRY,
            'provider' => $provider,
            'environment' => $environment,
            'schema_version' => self::SCHEMA_VERSION,
            'document_type' => $document->type->value,
            'series' => $document->type->series(),
            'number' => $document->encf,
            'issuer_tax_id' => $issuer,
            'identity_key' => self::identityKey($issuer, $document->encf),
            'status' => FiscalDocumentStatusEnum::DRAFT,
            'issued_at' => $issuedAt,
            'retain_until' => $issuedAt->copy()->addYears(self::RETENTION_YEARS),
            'regime_data' => $regimeData,
            ...$this->snapshot($document),
        ];
    }

    public function snapshot(FiscalPayloadInterface $payload): array
    {
        $document = EcfDocument::narrow($payload);

        return [
            'receiver_tax_id' => Str::trimToNull($document->buyer->taxId),
            'currency' => strtoupper($document->currency),
            'exchange_rate' => $document->exchangeRate,
            'taxable_total' => $document->totals->taxed,
            'exempt_total' => $document->totals->exempt,
            'tax_total' => $document->totals->itbis,
            'total' => $document->totals->total,
        ];
    }

    public static function type(FiscalDocumentTypeInterface $type): EcfTypeEnum
    {
        return $type instanceof EcfTypeEnum
            ? $type
            : throw new InvalidArgumentException('The DR regime was handed a ' . $type::class . ' document type.');
    }

    public function receivedAttributes(
        ReceivedFiscalDocumentInterface $received,
        CompanyInterface $company,
        string $environment
    ): array {
        $received = ReceivedEcf::narrow($received);
        $issuedAt = Carbon::parse($received->issueDate);

        return [
            'country' => self::COUNTRY,
            'provider' => $received->provider,
            'environment' => $environment,
            'schema_version' => self::SCHEMA_VERSION,
            'document_type' => $received->type->value,
            'series' => $received->type->series(),
            'number' => $received->encf,
            'issuer_tax_id' => $received->vendorTaxId,
            'receiver_tax_id' => self::issuerTaxIdFor($company),
            'identity_key' => self::identityKey($received->vendorTaxId, $received->encf),
            'authority_id' => $received->encf,
            'authority_seal' => Str::trimToNull($received->securityCode),
            'status' => FiscalDocumentStatusEnum::ACCEPTED,
            'issued_at' => $issuedAt,
            'signed_at' => DateHelper::tryParseCarbon($received->signedAt),
            'retain_until' => $issuedAt->copy()->addYears(self::RETENTION_YEARS),
            'currency' => strtoupper($received->currency),
            'exchange_rate' => $received->exchangeRate,
            'taxable_total' => $received->totals->taxed,
            'exempt_total' => $received->totals->exempt,
            'tax_total' => $received->totals->itbis,
            'total' => $received->totals->total,
            'regime_data' => [
                'vendor_name' => $received->vendorName,
                'stamp_url' => $received->qrUrl,
                'references' => $received->reference?->toArray(),
            ],
            'authority_data' => ['signed_at' => $received->signedAt],
        ];
    }

    public function sealFromStamp(string $stampUrl): ?string
    {
        return EcfQrCode::params($stampUrl)['CodigoSeguridad'] ?? null;
    }

    public function renderInvoicePdf(Invoice $invoice, Users $user): Filesystem
    {
        return new EcfInvoicePdfService($invoice)->generate($user);
    }
}
