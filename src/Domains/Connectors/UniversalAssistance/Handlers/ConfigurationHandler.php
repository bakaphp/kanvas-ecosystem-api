<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Handlers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\UniversalAssistance\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class ConfigurationHandler
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Validate that all required Universal Assistance configurations are set
     */
    public function validateConfiguration(): bool
    {
        $requiredConfigs = [
            ConfigurationEnum::BASE_URL,
            ConfigurationEnum::USERNAME,
            ConfigurationEnum::PASSWORD,
            ConfigurationEnum::ORGANIZATION,
        ];

        foreach ($requiredConfigs as $config) {
            $value = $this->app->get($config->value);
            if (empty($value)) {
                throw new ValidationException("Missing required configuration: {$config->value}");
            }
        }

        return true;
    }

    /**
     * Get all Universal Assistance configuration values
     */
    public function getConfiguration(): array
    {
        return [
            'base_url' => $this->app->get(ConfigurationEnum::BASE_URL->value),
            'username' => $this->app->get(ConfigurationEnum::USERNAME->value),
            'organization' => $this->app->get(ConfigurationEnum::ORGANIZATION->value),
            'wsdl_quote' => $this->app->get(ConfigurationEnum::WSDL_QUOTE->value),
            'wsdl_voucher' => $this->app->get(ConfigurationEnum::WSDL_VOUCHER->value),
            'wsdl_query' => $this->app->get(ConfigurationEnum::WSDL_QUERY->value),
        ];
    }
}
