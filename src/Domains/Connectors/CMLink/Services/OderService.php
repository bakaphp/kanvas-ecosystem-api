<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Domains\Connectors\CMLink\Client;

class OrderService
{
    protected Client $client;

    public function __construct(
        AppInterface $app,
        CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function createOrder(
        string $thirdOrderId,
        string $iccid,
        int $quantity,
        int $isRefuel = 0
    ): array {
        return $this->client->post('/aep/APP_createOrder_SBO/v1', [
            'third_order_id' => $thirdOrderId,
            'iccid' => $iccid,
            'quantity' => $quantity,
            'is_refuel' => $isRefuel,
        ]);
    }
}
