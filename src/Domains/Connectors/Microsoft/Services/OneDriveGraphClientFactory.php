<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

class OneDriveGraphClientFactory
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function make(): GraphServiceClient
    {
        $tenantId = $this->resolve(ConfigurationEnum::TENANT_ID);
        $clientId = $this->resolve(ConfigurationEnum::CLIENT_ID);
        $clientSecret = $this->resolve(ConfigurationEnum::CLIENT_SECRET);

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new ValidationException(
                'Microsoft Graph requires microsoft_tenant_id, microsoft_client_id, and microsoft_client_secret '
                . 'configured on the company or, as a fallback, on the app.'
            );
        }

        return new GraphServiceClient(
            new ClientCredentialContext($tenantId, $clientId, $clientSecret),
            ['https://graph.microsoft.com/.default'],
        );
    }

    private function resolve(ConfigurationEnum $key): string
    {
        $companyValue = $this->company->get($key->value);

        return filled($companyValue)
            ? (string) $companyValue
            : (string) $this->app->get($key->value);
    }
}
