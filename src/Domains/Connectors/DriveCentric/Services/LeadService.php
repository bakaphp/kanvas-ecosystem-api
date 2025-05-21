<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Companies\Models\Companies;
class LeadService
{
    public Client $client;
    public function __construct(
        protected Apps $app,
        protected Companies $companies
    ) {
        $this->client = new Client($this->app, $this->companies);
    }

    public function create(array $lead): array
    {
        $client = $this->client->getClient();
        $response = $client->post('{+endpoint}/api/stores/{+storeId}/deal/upsert', [
            'deal' => $lead,
        ]);
        return $response->json()['deal'];
    }
}
