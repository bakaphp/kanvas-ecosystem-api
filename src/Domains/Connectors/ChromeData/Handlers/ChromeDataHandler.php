<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\Handlers;

use Exception;
use Kanvas\Connectors\ChromeData\Client;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class ChromeDataHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $accountNumber = $this->data['account_number'] ?? null;
        $accountSecret = $this->data['account_secret'] ?? null;
        $country = $this->data['country'] ?? 'US';
        $language = $this->data['language'] ?? 'en';
        $useCompany = $this->data['use_company'] ?? false;

        if (empty($accountNumber) || empty($accountSecret)) {
            throw new ValidationException('ChromeData account number and secret are required');
        }

        // Save configuration to app settings
        $this->app->set(ConfigurationEnum::ACCOUNT_NUMBER->value, $accountNumber);
        $this->app->set(ConfigurationEnum::ACCOUNT_SECRET->value, $accountSecret);
        $this->app->set(ConfigurationEnum::COUNTRY->value, $country);
        $this->app->set(ConfigurationEnum::LANGUAGE->value, $language);

        // Test the connection
        try {
            $client = new Client($this->app, $useCompany ? $this->company : null);
            $years = $client->getModelYears();

            return ! empty($years->modelYear);
        } catch (Exception $e) {
            throw new ValidationException('Failed to connect to ChromeData: ' . $e->getMessage());
        }
    }
}
