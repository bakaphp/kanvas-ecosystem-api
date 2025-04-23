<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Leads;

use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;

class Status
{
    /**
     * Get all lead source.
     *
     * @param Dealer $dealer
     * @param User   $user
     */
    public static function getAll(): array
    {
        $client = new Client(0, 0);

        $response = $client->get('/leadStatuses', [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return $response;
    }
}
