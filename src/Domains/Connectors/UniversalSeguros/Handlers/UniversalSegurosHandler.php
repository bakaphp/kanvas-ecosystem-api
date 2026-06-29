<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\UniversalSeguros\Client;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;
use Kanvas\Exceptions\ValidationException;
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
        $scopes = (string) ($this->data['scopes'] ?? ConfigurationEnum::DEFAULT_SCOPES);

        if ($clientId === '' || $clientSecret === '') {
            throw new ValidationException('Universal Seguros client_id and client_secret are required');
        }

        if (EnvironmentEnum::tryFrom($environment) === null) {
            throw new ValidationException('Universal Seguros environment must be one of: qa, prod');
        }

        $this->company->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->company->set(ConfigurationEnum::CLIENT_SECRET->value, $clientSecret);
        $this->company->set(ConfigurationEnum::ENVIRONMENT->value, $environment);
        $this->company->set(ConfigurationEnum::SCOPES->value, $scopes);

        try {
            new Client($this->app, $this->company)->auth();
        } catch (Throwable $e) {
            throw new ValidationException('Universal Seguros authentication failed: ' . $e->getMessage());
        }

        return true;
    }
}
