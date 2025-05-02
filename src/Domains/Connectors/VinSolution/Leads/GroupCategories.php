<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Leads;

use Kanvas\Connectors\VinSolution\Client;

class GroupCategories
{
    /**
     * Get all lead source.
     *
     */
    public static function getAll(): array
    {
        $client = new Client(0, 0);

        $response = $client->get('/leadGroupCategories', [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return $response;
    }
}
