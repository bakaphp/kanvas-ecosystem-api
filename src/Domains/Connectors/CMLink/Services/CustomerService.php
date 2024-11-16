<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Domains\Connectors\CMLink\Client;
use Kanvas\Guild\Customers\Models\People;

class CustomerService
{
    protected Client $client;

    public function __construct(
        protected People $people
    ) {
        $this->client = new Client($people->app, $people->company);
    }

    public function getEsimInfo(string $iccid): array
    {
        return $this->client->post('/aep/SBO_queryEsimCardInfo/v1', [
            'iccid' => $iccid,
        ]);
    }

    public function getUserPlans(string $language, string $iccid): array
    {
        return $this->client->post('/aep/APP_getSubedUserDataBundle_SBO/v1', [
            'language' => $language,
            'iccid' => $iccid,
        ]);
    }

    public function unsubscribePlan(string $orderId): array
    {
        return $this->client->post('/aep/SBO_channel_unsubscribe/v1', [
            'orderId' => $orderId,
        ]);
    }
}
