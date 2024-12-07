<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\CMLink\Client;

class PlanService
{
    protected Client $client;

    public function __construct(
        AppInterface $app,
        CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Activate a plan.
     *
     * @param string $dataBundleId ID of the data bundle to activate.
     * @param string $iccid ICCID of the SIM card.
     * @return array
     */
    public function activatePlan(
        string $dataBundleId,
        string $iccid
    ): array {
        return $this->client->post('/aep/APP_activeDataBundle_SBO/v1', [
            'dataBundleId' => $dataBundleId,
            'ICCID' => $iccid,
            'accessToken' => $this->client->getAccessToken(),
        ]);
    }

    /**
     * Terminate a plan.
     *
     * @param string $iccid ICCID of the SIM card.
     * @param string $dataBundleId ID of the data bundle to terminate.
     * @return array
     */
    public function terminatePlan(string $iccid, string $dataBundleId): array
    {
        return $this->client->post('/aep/SBO_package_end/v1', [
            'iccidPackageList' => [
                [
                    'iccid' => $iccid,
                    'packageid' => $dataBundleId,
                ],
            ],
            'accessToken' => $this->client->getAccessToken(),
        ]);
    }
}