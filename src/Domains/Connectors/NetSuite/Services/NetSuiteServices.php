<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Traits\UseNetSuiteCustomerSearchTrait;
use Kanvas\Exceptions\ValidationException;

class NetSuiteServices
{
    use UseNetSuiteCustomerSearchTrait;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $this->service = (new Client($app, $company))->getService();
    }

    /**
     * Set the shopify credentials into companies custom fields.
     */
    public static function setup(NetSuiteDto $data): bool
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
