<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Vehicles;

use Exception;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;

class Interest
{
    public string $href;
    public int $count;
    public array $items;

    /**
     * Initialize.
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
    public static function getByLeadId(Dealer $dealer, User $user, int $leadsId): Interest
    {
        $client = new Client($dealer->id, $user->id);

        $response = $client->get('/vehicles/interest?leadId=' . $leadsId, [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return new Interest($response);
    }

    /**
     * Create a new Interest.
     */
    public static function create(Dealer $dealer, User $user, int $leadsId, array $data): self
    {
        $client = new Client($dealer->id, $user->id);

        if (! isset($data['vin'])) {
            throw new Exception('Data must contain vehicles');
        }

        $interest = [];
        $interest['lead'] = 'https://api.vinsolutions.com/leads/id/' . $leadsId;
        $interest['vehicles'][] = $data;

        $response = $client->post(
            '/vehicles/interest',
            json_encode($interest),
            [
                'headers' => [
                    'Content-Type' => 'application/vnd.coxauto.v1+json',
                ],
            ]
        );

        return self::getByLeadId($dealer, $user, $leadsId);
    }

    /**
     * Update vehicle interest.
     */
    public static function update(Dealer $dealer, User $user, string $id, array $data): bool
    {
        $client = new Client($dealer->id, $user->id);

        $response = $client->put(
            '/vehicles/interest/id/' . $id,
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
        $id = str_replace('https://api.vinsolutions.com/vehicles/interest/id/', '', $this->items[$index]['href']);
        unset($this->items[$index]['href'], $this->items[$index]['lead'], $this->items[$index]['downPaymentRequested'], $this->items[$index]['monthlyPaymentRequested'], $this->items[$index]['reservationPaymentRequested'], $this->items[$index]['paymentMethod']);

        return [
            'id' => $id,
            'vehicle' => $this->items[$index],
        ];
    }
}
