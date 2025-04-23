<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\CMLink\Client;

class CarrierService
{
    protected Client $client;

    public function __construct(
        AppInterface $app,
        CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function getAll(
        string|int $language = 2,
        ?string $mcc = null,
        ?string $continent = null
    ): array {
        $body = [
            'language'  => $language,
            'mcc'       => $mcc,
            'continent' => $continent,
        ];

        return $this->client->post('/aep/APP_queryCarrier_SBO/v1', $body);
    }

    public function getAllDataBundle(
        string $language = '2',
        int $beginIndex = 0,
        int $count = 50,
        ?string $mcc = null,
    ): array {
        $body = [
            'accessToken'     => $this->client->getAccessToken(),
            'Partner'         => '',
            'dataBundleId'    => '',
            'dataBundleName'  => '',
            'Group_id'        => '',
            'language'        => $language,
            'country'         => 'US',
            'mcc'             => $mcc,
            'status'          => '1',
            'currency'        => ['USD'],
            'beginIndex'      => $beginIndex,
            'count'           => $count,
            'cooperationMode' => '1',
        ];

        return $this->client->post('/aep/app_getDataBundle_SBO/v1', $body);
    }
}
