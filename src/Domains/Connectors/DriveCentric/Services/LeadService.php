<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\DataTransferObject\LeadDriveCentric;

class LeadService
{
    public function __construct(
        protected Apps $app,
    ) {
    }

    public function create(array $lead): array
    {
        $client = (new Client($this->app))->getClient();
        $response = $client->post('{+endpoint}/api/stores/{+storeId}/deal/upsert', [
            'deal' => $lead
        ]);
        return $response->json();
    }
}
