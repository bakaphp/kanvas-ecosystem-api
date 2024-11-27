<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESimGo\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\ESimGo\Client;

class ESimService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    public function getDetails(string $iccid): array
    {
        return $this->client->get('/v2.4/esims/' . $iccid);
    }

    public function getLocation(string $iccid): array
    {
        return $this->client->get('/v2.4/esims/' . $iccid . '/location');
    }

    public function getHistory(string $iccid): array
    {
        return $this->client->get('/v2.4/esims/' . $iccid . '/history');
    }

    public function getAppliedBundleStatus(string $iccid, string $bundle): array
    {
        $response = $this->client->get('/v2.4/esims/' . $iccid . '/bundles/' . $bundle);

        return $response['assignments'][0] ?? [];
    }
}
