<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Leads;

use Kanvas\Connectors\VinSolution\Client;

class Types
{
    /**
     * Get all lead source.
     */
    public static function getAll(): array
    {
        $client = new Client(0, 0);

        $response = $client->get('/leadTypes', [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return $response;
    }
}
