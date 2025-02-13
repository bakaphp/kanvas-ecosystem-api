<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Vehicles;

use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;

class TradeIn
{
    public string $href;
    public int $count;
    public array $items;

    /**
     * Initialize
     */
    public function __construct(array $data)
    {
        $this->href = $data['href'];
        $this->count = $data['count'] ?? 0;
        $this->items = $data['items'];
    }

    /**
     * Get a contact by its ID.
     */
    public static function getByLeadId(Dealer $dealer, User $user, int $leadsId): TradeIn
    {
        $client = new Client($dealer->id, $user->id);

        $response = $client->get('/vehicles/trade?leadId=' . $leadsId, [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return new TradeIn($response);
    }

    /**
     * Update vehicle interest.
     */
    public static function update(Dealer $dealer, User $user, string $id, array $data): bool
    {
        $client = new Client($dealer->id, $user->id);

        $response = $client->put(
            '/vehicles/trade/id/' . $id,
            json_encode(
                $data
            ),
            [
                'headers' => [
                    'Content-Type' => 'application/vnd.coxauto.v1+json',
                ],
            ]
        );

        return true;
    }

    /**
     * Get specific interest.
     */
    public function getVehicleByIndex(int $index): array
    {
        $id = str_replace('https://api.vinsolutions.com/vehicles/trade/id/', '', $this->items[$index]['href']);
        unset($this->items[$index]['href'], $this->items[$index]['lead'], $this->items[$index]['downPaymentRequested'], $this->items[$index]['monthlyPaymentRequested'], $this->items[$index]['reservationPaymentRequested'], $this->items[$index]['paymentMethod']);

        return [
            'id' => $id,
            'vehicle' => $this->items[$index],
        ];
    }
}
