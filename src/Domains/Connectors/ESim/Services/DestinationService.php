<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\ESim\Client;

class DestinationService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function getPlans(
        ?string $code = null,
        ?string $provider = null,
        ?string $coverage = null,
        ?int $page = 1,
        ?int $limit = 25
    ): array {
        $query = http_build_query([
            'code' => $code,
            'provider' => $provider,
            'coverage' => $coverage,
            'page' => $page,
            'limit' => $limit,
        ]);

        return $this->client->get('/api/v2/destinations/plans?' . $query);
    }
}
