<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Baka\Support\AllowedClass;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\EcfProviderInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;

/**
 * The company's e-invoicing provider, from its `scribe-ecf-provider` setting. One provider per company: the
 * authority's directory points each taxpayer at a single provider, so this is never chosen per document.
 */
class EcfProviderResolver
{
    public function providerKey(Companies $company): ?string
    {
        $value = $company->get(ConfigurationEnum::ECF_PROVIDER->value);

        return is_string($value) ? Str::trimToNull(strtolower($value)) : null;
    }

    /**
     * The country the configured provider issues for; null without one, or when the key is not installed.
     */
    public function providerCountry(Companies $company): ?string
    {
        $key = $this->providerKey($company);
        $class = $key !== null ? AllowedClass::resolve(config("scribe.ecf_providers.{$key}"), EcfProviderInterface::class) : null;

        return $class !== null ? $class::country() : null;
    }

    public function issuesEcf(Companies $company): bool
    {
        return $this->providerKey($company) !== null;
    }

    public function resolve(Apps $app, Companies $company): EcfProviderInterface
    {
        $key = $this->providerKey($company)
            ?? throw new ValidationException("Company {$company->getId()} has no e-CF provider configured.");

        return $this->resolveKey($app, $company, $key);
    }

    /**
     * A fiscal row is always answered by the provider and in the environment it was issued in, even after the
     * company switched either: a company moved to production must not poll production for its test documents.
     */
    public function resolveFor(FiscalDocument $fiscal): EcfProviderInterface
    {
        $key = Str::trimToNull($fiscal->provider)
            ?? throw new ValidationException("Fiscal document {$fiscal->uuid} has no provider recorded.");

        return $this->resolveKey(
            $fiscal->app,
            $fiscal->company,
            $key,
            $fiscal->ecfEnvironment()
        );
    }

    private function resolveKey(
        Apps $app,
        Companies $company,
        string $key,
        ?EcfEnvironmentEnum $environment = null
    ): EcfProviderInterface {
        $class = AllowedClass::resolve(
            config("scribe.ecf_providers.{$key}"),
            EcfProviderInterface::class
        ) ?? throw new ValidationException("e-CF provider '{$key}' is not installed on this platform.");

        return new $class(app: $app, company: $company, environment: $environment);
    }
}
