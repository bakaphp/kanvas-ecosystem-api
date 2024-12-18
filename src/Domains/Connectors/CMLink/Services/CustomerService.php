<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\CMLink\Client;

class CustomerService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Get eSIM Information.
     *
     * @param string $iccid ICCID of the eSIM card.
     */
    public function getEsimInfo(string $iccid): array
    {
        return $this->client->post('/aep/SBO_queryEsimCardInfo/v1', [
            'iccid' => $iccid,
            'accessToken' => $this->client->getAccessToken(),
        ]);
    }

    /**
     * Query User’s Active Plans.
     *
     * @param string $iccid ICCID of the SIM card.
     * @param string|null $status Plan status to filter (1: In use, 2: Used, 3: Unused, 4: Expired).
     * @param int $beginIndex Start index for pagination (default: 0).
     * @param int $count Number of results to return (default: 50).
     */
    public function getUserPlans(
        string $iccid,
        ?string $status = null,
        int $beginIndex = 0,
        int $count = 50
    ): array {
        $payload = [
            'iccid' => $iccid,
            'beginIndex' => $beginIndex,
            'count' => $count,
            'accessToken' => $this->client->getAccessToken(),
        ];

        if ($status) {
            $payload['status'] = $status;
        }

        return $this->client->post('/aep/APP_getSubedUserDataBundle_SBO/v1', $payload);
    }

    /**
     * Query Usage Details.
     *
     * @param string $iccid ICCID of the SIM card.
     * @param string|null $beginTime Start date for usage data (format: YYYYMMDD).
     * @param string|null $endTime End date for usage data (format: YYYYMMDD).
     */
    public function getUsageDetails(
        string $iccid,
        ?string $beginTime = null,
        ?string $endTime = null
    ): array {
        $payload = [
            'iccid' => $iccid,
            'accessToken' => $this->client->getAccessToken(),
        ];

        if ($beginTime) {
            $payload['beginTime'] = $beginTime;
        }

        if ($endTime) {
            $payload['endTime'] = $endTime;
        }

        return $this->client->post('/aep/APP_getSubscriberAllQuota_SBO/v1', $payload);
    }

    /**
     * Unsubscribe Plan.
     *
     * @param string $orderId Order ID to unsubscribe from.
     */
    public function unsubscribePlan(string $orderId): array
    {
        return $this->client->post('/aep/SBO_channel_unsubscribe/v1', [
            'orderId' => $orderId,
            'accessToken' => $this->client->getAccessToken(),
        ]);
    }
}
