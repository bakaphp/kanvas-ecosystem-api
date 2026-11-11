<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Enums;

/**
 * Which national id `organizations.tax_id` holds. Codes follow Stripe's tax id types (`{country}_{kind}`).
 * `accepts()` takes the value already through `Str::normalizeTaxId()`, so a plain number arrives as digits.
 */
enum TaxIdTypeEnum: string
{
    case DO_RNC = 'do_rnc';
    case DO_CEDULA = 'do_cedula';
    case SV_NIT = 'sv_nit';
    case SV_NRC = 'sv_nrc';
    case PA_RUC = 'pa_ruc';
    case MX_RFC = 'mx_rfc';
    case US_EIN = 'us_ein';
    case OTHER = 'other';

    private const string MX_RFC_PATTERN = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u';

    public function country(): ?string
    {
        return match ($this) {
            self::DO_RNC, self::DO_CEDULA => 'DO',
            self::SV_NIT, self::SV_NRC => 'SV',
            self::PA_RUC => 'PA',
            self::MX_RFC => 'MX',
            self::US_EIN => 'US',
            self::OTHER => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DO_RNC => 'RNC',
            self::DO_CEDULA => 'Cédula',
            self::SV_NIT => 'NIT',
            self::SV_NRC => 'NRC',
            self::PA_RUC => 'RUC',
            self::MX_RFC => 'RFC',
            self::US_EIN => 'EIN',
            self::OTHER => 'Tax ID',
        };
    }

    /**
     * Strict where the rule is known (DO, MX, US). SV and PA are permissive on purpose: a NIT is 14 digits
     * but a DUI (9) is accepted as NIT since 2021, an NRC's length varies, and a Panamanian RUC's dashes
     * are part of it and its shape differs by holder type — refusing a real id is worse than storing it.
     */
    public function accepts(string $normalized): bool
    {
        return match ($this) {
            self::DO_RNC, self::US_EIN => preg_match('/^\d{9}$/', $normalized) === 1,
            self::DO_CEDULA => preg_match('/^\d{11}$/', $normalized) === 1,
            self::SV_NIT => preg_match('/^(\d{9}|\d{14})$/', $normalized) === 1,
            self::SV_NRC => preg_match('/^\d{2,8}$/', $normalized) === 1,
            self::PA_RUC => preg_match('/^[A-Z0-9][A-Z0-9\-]{2,29}$/i', $normalized) === 1,
            self::MX_RFC => preg_match(self::MX_RFC_PATTERN, mb_strtoupper((string) preg_replace('/[\s\-]/', '', $normalized))) === 1,
            self::OTHER => $normalized !== '',
        };
    }

    /**
     * Null when the country and shape don't settle it (an SV number that could be a NIT or an NRC, any
     * country without a rule here) — the caller then has to ask, not guess.
     */
    public static function infer(?string $companyCountry, string $normalizedTaxId): ?self
    {
        $candidates = match (strtoupper((string) $companyCountry)) {
            'DO' => [self::DO_RNC, self::DO_CEDULA],
            'MX' => [self::MX_RFC],
            'US' => [self::US_EIN],
            'PA' => [self::PA_RUC],
            'SV' => [self::SV_NIT, self::SV_NRC],
            default => [],
        };

        $matches = array_values(array_filter(
            $candidates,
            fn (self $type): bool => $type->accepts($normalizedTaxId)
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
