<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Handlers;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Humano\Enums\ConfigurationEnum;
use Kanvas\Connectors\Humano\Enums\EnvironmentEnum;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Jobs\SyncInsuranceProductsJob;
use Override;
use Throwable;

class HumanoHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $subscriptionKey = (string) ($this->data['subscription_key'] ?? '');
        $userKey = (string) ($this->data['user_key'] ?? '');
        $mediatorCode = (string) ($this->data['mediator_code'] ?? '');
        $environment = (string) ($this->data['environment'] ?? EnvironmentEnum::DEV->value);

        if ($subscriptionKey === '' || $userKey === '' || $mediatorCode === '') {
            throw new ValidationException(
                'Humano subscription_key, user_key and mediator_code are required'
            );
        }

        if (EnvironmentEnum::tryFrom($environment) === null) {
            throw new ValidationException('Humano environment must be one of: dev, prod');
        }

        $insurerCompany = $this->resolveInsurerCompany();

        $this->company->set(ConfigurationEnum::SUBSCRIPTION_KEY->value, $subscriptionKey);
        $this->company->set(ConfigurationEnum::USER_KEY->value, $userKey);
        $this->company->set(ConfigurationEnum::MEDIATOR_CODE->value, $mediatorCode);
        $this->company->set(ConfigurationEnum::ENVIRONMENT->value, $environment);
        $this->company->set(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value, $insurerCompany->getId());

        // Without a default, every insuranceQuote would have to name the provider.
        $this->company->set(InsuranceCustomFieldEnum::PROVIDER->value, HumanoProvider::NAME);

        $this->assertCredentialsWork();

        SyncInsuranceProductsJob::dispatch(
            $this->app,
            $this->company,
            HumanoProvider::NAME
        );

        return true;
    }

    /**
     * There is no token endpoint to round-trip against, so the cheapest authenticated
     * read stands in for one: provincias takes no parameters and every intermediary
     * can see it, so a failure here is a credential problem and nothing else.
     */
    protected function assertCredentialsWork(): void
    {
        try {
            new HumanoService($this->app, $this->company)->getProvincias();
        } catch (Throwable $e) {
            throw new ValidationException('Humano authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Required, so a misconfigured setup can't seed the catalog under the aliado.
     */
    protected function resolveInsurerCompany(): Companies
    {
        $insurerCompanyId = (int) ($this->data['insurer_companies_id'] ?? 0);

        if ($insurerCompanyId === 0) {
            throw new ValidationException(
                'Humano insurer_companies_id is required — the insurer company in Kanvas'
            );
        }

        try {
            return Companies::getById($insurerCompanyId);
        } catch (Throwable) {
            throw new ValidationException(
                'Humano insurer_companies_id ' . $insurerCompanyId . ' is not a company in Kanvas'
            );
        }
    }
}
