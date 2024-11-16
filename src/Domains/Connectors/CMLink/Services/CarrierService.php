<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Domains\Connectors\CMLink\Client;

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
        string $language,
        ?string $mcc = null,
        ?string $continent = null
    ): array {
        $body = [
            'language' => $language,
            'mcc' => $mcc,
            'continent' => $continent,
        ];

        return $this->client->post('/aep/APP_queryCarrier_SBO/v1', $body);
    }

    public function getAllDataBundle(
        string $language,
        int $beginIndex = 0,
        int $count = 50
    ): array {
        $body = [
            'language' => $language,
            'beginIndex' => $beginIndex,
            'count' => $count,
        ];

        return $this->client->post('/aep/app_getDataBundle_SBO/v1', $body);
    }
}
