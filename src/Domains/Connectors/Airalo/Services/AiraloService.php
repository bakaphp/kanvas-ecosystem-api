<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Airalo\Client;

class AiraloService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    /**
     * Get eSIM status information from Simlimites.
     * @todo Change it later to direct connection to airalo instead of simlimites
     */
    public function getEsimStatus(string $iccid, string $bundle): array
    {
        return $this->client->get('/api/v1/airalo/check/status/' . $iccid . '/' . $bundle);
    }
}
