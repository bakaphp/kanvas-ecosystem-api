<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes;

use Baka\Contracts\CompanyInterface;
use Baka\Support\AllowedClass;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;

/**
 * The regimes this platform runs, from `config/scribe.php` `fiscal_regimes` (ISO country => class).
 */
class FiscalRegimes
{
    /**
     * @return list<FiscalRegimeInterface>
     */
    public static function all(): array
    {
        return array_values(array_filter(array_map(
            fn (string $country): ?FiscalRegimeInterface => self::find($country),
            array_keys((array) config('scribe.fiscal_regimes', []))
        )));
    }

    public static function find(?string $country): ?FiscalRegimeInterface
    {
        $country = strtoupper(trim((string) $country));
        $class = AllowedClass::resolve(config("scribe.fiscal_regimes.{$country}"), FiscalRegimeInterface::class);

        return $class !== null ? new $class() : null;
    }

    public static function for(?string $country): FiscalRegimeInterface
    {
        return self::find($country)
            ?? throw new ValidationException("Electronic invoicing is not supported for country '{$country}'.");
    }

    /**
     * A well-formed id of one of the types the authority's registry holds.
     */
    public static function isRegistryTaxId(FiscalRegimeInterface $regime, ?string $taxId): bool
    {
        $taxId = Str::normalizeTaxId($taxId);

        foreach ($regime->taxIdTypes() as $type) {
            if ($taxId !== null && $type->accepts($taxId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The country its e-invoicing provider issues for, since a provider maps one country's documents; a company
     * not connected yet (a range loaded during onboarding) falls back to its own country.
     */
    public static function forCompany(Companies $company): ?FiscalRegimeInterface
    {
        return self::find(new EcfProviderResolver()->providerCountry($company) ?? $company->countryCode());
    }

    /**
     * For work that cannot proceed without one (issuing, loading a range, rendering the legal PDF).
     */
    public static function requireForCompany(?CompanyInterface $company): FiscalRegimeInterface
    {
        $regime = $company instanceof Companies ? self::forCompany($company) : null;

        return $regime ?? throw new ValidationException(
            'This company has no electronic-invoicing regime yet: connect its e-invoicing provider, or set the company\'s country.'
        );
    }
}
