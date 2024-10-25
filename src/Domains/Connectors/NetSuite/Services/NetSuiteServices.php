<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class NetSuiteServices
{
    /**
     * Set the shopify credentials into companies custom fields.
     */
    public static function netSuitSetup(NetSuiteDto $data): bool
    {
        $configData = [
            'account' => $data->account,
            'consumerKey' => $data->consumerKey,
            'consumerSecret' => $data->consumerSecret,
            'token' => $data->token,
            'tokenSecret' => $data->tokenSecret,
        ];

        $requiredKeys = ['account', 'consumerKey', 'consumerSecret', 'token', 'tokenSecret'];
        $missingKeys = [];

        foreach ($requiredKeys as $key) {
            if (empty($configData[$key])) {
                $missingKeys[] = $key;
            }
        }

        if (! empty($missingKeys)) {
            throw new ValidationException('NetSuite configuration is missing the following keys: ' . implode(', ', $missingKeys));
        }

        return $data->app->set(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value, $configData);
    }
}
