<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Dealers;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\VinSolution\Client;

class Dealer
{
    public int $id;
    public string $name;
    public string $city;
    public string $state;

    /**
     * Initialize a dealer.
     */
    public function __construct(int $id, string $name, string $city, string $state)
    {
        $this->id = $id;
        $this->name = $name;
        $this->city = $city;
        $this->state = $state;
    }

    /**
     * Get all the dealers who we have permission for the current app Key.
     */
    public static function getAll(AppInterface $app): array
    {
        $client = new Client(0, 0, $app);
        $response = $client->get('/gateway/v1/organization/dealers');

        $dealers = [];
        if (count($response)) {
            foreach ($response['Items'] as $item) {
                $dealers[$item['DealerId']] = new Dealer($item['DealerId'], $item['Name'], $item['City'], $item['State']);
            }
        }

        return $dealers;
    }

    /**
     * Get a dealer by its ID.
     */
    public static function getById(int $id, AppInterface $app): Dealer
    {
        return self::getAll($app)[$id];
    }

    /**
     * Get all users fro the given dealer.
     */
    public static function getUsers(Dealer $dealer, AppInterface $app): array
    {
        $client = new Client($dealer->id, 0, $app);
        $response = $client->get('/gateway/v1/tenant/user?dealerId=' . $dealer->id);

        $users = [];
        if (count($response)) {
            foreach ($response as $item) {
                $users[] = new User($item);
            }
        }

        return $users;
    }

    /**
     * Get a individual user by its ID.
     */
    public static function getUser(Dealer $dealer, int $userId, AppInterface $app): User
    {
        $client = new Client($dealer->id, 0, $app);
        $response = $client->get('/gateway/v1/tenant/user/id/' . $userId . '?dealerId=' . $dealer->id);

        return new User($response);
    }
}
