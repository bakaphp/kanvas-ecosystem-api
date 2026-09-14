<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Handlers;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\UniversalSeguros\Client;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Jobs\SyncInsuranceProductsJob;
use Override;
use Throwable;

class UniversalSegurosHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $clientId = (string) ($this->data['client_id'] ?? '');
        $clientSecret = (string) ($this->data['client_secret'] ?? '');
        $environment = (string) ($this->data['environment'] ?? EnvironmentEnum::QA->value);
        $scopes = (string) ($this->data['scopes'] ?? ConfigurationEnum::defaultScopes());
        $verifySsl = filter_var($this->data['verify_ssl'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        if ($clientId === '' || $clientSecret === '') {
            throw new ValidationException('Universal Seguros client_id and client_secret are required');
        }

        if (EnvironmentEnum::tryFrom($environment) === null) {
            throw new ValidationException('Universal Seguros environment must be one of: qa, prod');
        }

        $insurerCompany = $this->resolveInsurerCompany();

        $this->company->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->company->set(ConfigurationEnum::CLIENT_SECRET->value, $clientSecret);
        $this->company->set(ConfigurationEnum::ENVIRONMENT->value, $environment);
        $this->company->set(ConfigurationEnum::SCOPES->value, $scopes);
        // Stored as '1'/'0' because a stored false round-trips as an empty string,
        // which Client::resolveVerifySsl reads as "unset" and defaults back to true.
        $this->company->set(ConfigurationEnum::VERIFY_SSL->value, $verifySsl ? '1' : '0');
        $this->company->set(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value, $insurerCompany->getId());

        // Without a default, every insuranceQuote would have to name the provider.
        $this->company->set(InsuranceCustomFieldEnum::PROVIDER->value, UniversalSegurosProvider::NAME);

        try {
            new Client($this->app, $this->company)->auth();
        } catch (Throwable $e) {
            throw new ValidationException('Universal Seguros authentication failed: ' . $e->getMessage());
        }

        SyncInsuranceProductsJob::dispatch(
            $this->app,
            $this->company,
            UniversalSegurosProvider::NAME
        );

        return true;
    }

    /**
     * Required, so a misconfigured setup can't seed the catalog under the aliado.
     */
    protected function resolveInsurerCompany(): Companies
    {
        $insurerCompanyId = (int) ($this->data['insurer_companies_id'] ?? 0);

        if ($insurerCompanyId === 0) {
            throw new ValidationException(
                'Universal Seguros insurer_companies_id is required — the insurer company in Kanvas'
            );
        }

        try {
            return Companies::getById($insurerCompanyId);
        } catch (Throwable) {
            throw new ValidationException(
                'Universal Seguros insurer_companies_id ' . $insurerCompanyId . ' is not a company in Kanvas'
            );
        }
    }
}
