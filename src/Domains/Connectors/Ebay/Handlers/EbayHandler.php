<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Ebay\Client;
use Kanvas\Connectors\Ebay\Enums\ConfigurationEnum;
use Kanvas\Connectors\Ebay\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class EbayHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $clientId = $this->data['client_id']
            ?? $this->company->get(ConfigurationEnum::EBAY_CLIENT_ID->value)
            ?? $this->app->get(ConfigurationEnum::EBAY_CLIENT_ID->value);
        $clientSecret = $this->data['client_secret']
            ?? $this->company->get(ConfigurationEnum::EBAY_CLIENT_SECRET->value)
            ?? $this->app->get(ConfigurationEnum::EBAY_CLIENT_SECRET->value);
        $refreshToken = $this->data['refresh_token'] ?? $this->company->get(CustomFieldEnum::EBAY_REFRESH_TOKEN->value);

        if ((! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '')
            && (! is_string($refreshToken) || $refreshToken === '')) {
            throw new ValidationException('eBay client credentials or a refresh token are required');
        }

        if (! $this->client()->validateCredentials(
            clientId: is_string($clientId) ? $clientId : null,
            clientSecret: is_string($clientSecret) ? $clientSecret : null,
            refreshToken: is_string($refreshToken) ? $refreshToken : null,
            environment: isset($this->data['environment']) && is_string($this->data['environment']) ? $this->data['environment'] : null,
        )) {
            return false;
        }

        foreach ([
            'client_id' => ConfigurationEnum::EBAY_CLIENT_ID,
            'client_secret' => ConfigurationEnum::EBAY_CLIENT_SECRET,
            'dev_id' => ConfigurationEnum::EBAY_DEV_ID,
            'redirect_uri' => ConfigurationEnum::EBAY_REDIRECT_URI,
            'environment' => ConfigurationEnum::EBAY_ENVIRONMENT,
            'ru_name' => ConfigurationEnum::EBAY_RU_NAME,
        ] as $inputKey => $settingKey) {
            if (array_key_exists($inputKey, $this->data) && $this->data[$inputKey] !== null) {
                $this->company->set($settingKey->value, $this->data[$inputKey]);
            }
        }

        foreach ([
            'access_token' => CustomFieldEnum::EBAY_ACCESS_TOKEN,
            'refresh_token' => CustomFieldEnum::EBAY_REFRESH_TOKEN,
            'token_expires_at' => CustomFieldEnum::EBAY_TOKEN_EXPIRES_AT,
            'refresh_token_expires_at' => CustomFieldEnum::EBAY_REFRESH_TOKEN_EXPIRES_AT,
            'seller_username' => CustomFieldEnum::EBAY_SELLER_USERNAME,
        ] as $inputKey => $settingKey) {
            if (array_key_exists($inputKey, $this->data) && $this->data[$inputKey] !== null) {
                $this->company->set($settingKey->value, $this->data[$inputKey]);
            }
        }

        return true;
    }

    /** @return list<string> */
    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::EBAY_CLIENT_ID->value,
            ConfigurationEnum::EBAY_CLIENT_SECRET->value,
            ConfigurationEnum::EBAY_DEV_ID->value,
            ConfigurationEnum::EBAY_REDIRECT_URI->value,
            ConfigurationEnum::EBAY_ENVIRONMENT->value,
            ConfigurationEnum::EBAY_RU_NAME->value,
            CustomFieldEnum::EBAY_ACCESS_TOKEN->value,
            CustomFieldEnum::EBAY_REFRESH_TOKEN->value,
            CustomFieldEnum::EBAY_TOKEN_EXPIRES_AT->value,
            CustomFieldEnum::EBAY_REFRESH_TOKEN_EXPIRES_AT->value,
            CustomFieldEnum::EBAY_SELLER_USERNAME->value,
        ];
    }

    protected function client(): Client
    {
        return new Client($this->app, $this->company);
    }
}
