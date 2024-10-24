<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Exceptions\ValidationException;
use NetSuite\NetSuiteService;

class Client
{
    protected string $apiUrl = 'https://webservices.netsuite.com';
    protected string $endPoint = '2021_1';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    public function getService(): NetSuiteService
    {
        $config = $this->app->get(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value);

        $config = [
            // required -------------------------------------
            'endpoint' => $this->endPoint,
            'host' => $this->apiUrl,
            'account' => $config['account'],
            'consumerKey' => $config['consumerKey'],
            'consumerSecret' => $config['consumerSecret'],
            'token' => $config['token'],
            'tokenSecret' => $config['tokenSecret'],
            // optional -------------------------------------
            'signatureAlgorithm' => 'sha256', // Defaults to 'sha256'
            'logging' => true,
            'log_path' => storage_path('logs/netsuite.log'),
            'log_format' => 'netsuite-php-%date-%operation',
            'log_dateformat' => 'Ymd.His.u',
        ];

        return new NetSuiteService($config);
    }

    public function setServiceConfig(array $config): void
    {
        $requiredKeys = ['account', 'consumerKey', 'consumerSecret', 'token', 'tokenSecret'];
        $missingKeys = [];

        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                $missingKeys[] = $key;
            }
        }

        if (! empty($missingKeys)) {
            throw new ValidationException('NetSuite configuration is missing the following keys: ' . implode(', ', $missingKeys));
        }

        $this->app->set(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value, $config);
    }
}
