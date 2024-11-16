<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Domains\Connectors\CMLink\Client;

class PlanService
{
    protected Client $client;

    public function __construct(
        AppInterface $app,
        CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function activatePlan(
        string $dataBundleId,
        string $mcc,
        ?string $iccid = null,
        ?string $himsi = null,
        ?string $msisdn = null
    ): array {
        $body = [
            'dataBundleId' => $dataBundleId,
            'mcc' => $mcc,
            'iccid' => $iccid,
            'hImsi' => $himsi,
            'msisdn' => $msisdn,
        ];

        return $this->client->post('/aep/APP_activeDataBundle_SBO/v1', $body);
    }

    public function terminatePlan(string $iccid, string $dataBundleId): array
    {
        return $this->client->post('/aep/SBO_package_end/v1', [
            'iccidPackageList' => [
                ['iccid' => $iccid, 'packageid' => $dataBundleId],
            ],
        ]);
    }
}
