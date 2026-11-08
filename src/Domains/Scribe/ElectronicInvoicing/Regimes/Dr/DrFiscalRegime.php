<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Baka\Contracts\CompanyInterface;
use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\FxRates\DataTransferObject\OfficialRateSource;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\ReceivedEcf;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;

/**
 * The Dominican Republic's half of a fiscal row: identity key, routing and the money snapshot at DGII's
 * two-decimal scale. Another country gets its own folder beside this one, registered in FiscalRegimes.
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

    /**
     * @param array<string, mixed> $regimeData
     * @return array<string, mixed>
     */
    public static function issued(
        EcfDocument $document,
        string $encf,
        CompanyInterface $company,
        ?string $provider,
        string $environment,
        array $regimeData = [],
    ): array {
        $issuer = self::requireIssuerTaxId($company);
        $issuedAt = Carbon::parse($document->issueDate);

        return [
            'country' => self::COUNTRY,
            'provider' => $provider,
            'environment' => $environment,
            'schema_version' => self::SCHEMA_VERSION,
            'document_type' => $document->type->value,
            'series' => $document->type->prefix(),
            'number' => $encf,
            'issuer_tax_id' => $issuer,
            'identity_key' => self::identityKey($issuer, $encf),
            'status' => FiscalDocumentStatusEnum::DRAFT,
            'issued_at' => $issuedAt,
            'retain_until' => $issuedAt->copy()->addYears(self::RETENTION_YEARS),
            'regime_data' => $regimeData,
            ...self::snapshot($document),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(EcfDocument $document): array
    {
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

    /**
     * A vendor's e-CF reaches us only after DGII accepted it, so the received row starts accepted.
     *
     * @return array<string, mixed>
     */
    public static function received(ReceivedEcf $received, CompanyInterface $company, string $environment): array
    {
        $issuedAt = Carbon::parse($received->issueDate);

        return [
            'country' => self::COUNTRY,
            'provider' => $received->provider,
            'environment' => $environment,
            'schema_version' => self::SCHEMA_VERSION,
            'document_type' => $received->type->value,
            'series' => $received->type->prefix(),
            'number' => $received->encf,
            'issuer_tax_id' => $received->vendorTaxId,
            'receiver_tax_id' => self::issuerTaxIdFor($company),
            'identity_key' => self::identityKey($received->vendorTaxId, $received->encf),
            'authority_id' => $received->encf,
            'authority_seal' => Str::trimToNull($received->securityCode),
            'status' => FiscalDocumentStatusEnum::ACCEPTED,
            'issued_at' => $issuedAt,
            'signed_at' => self::date($received->signedAt),
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

    public static function date(?string $value): ?Carbon
    {
        if (Str::trimToNull($value) === null) {
            return null;
        }

        return DateHelper::tryParseCarbon($value);
    }
}
