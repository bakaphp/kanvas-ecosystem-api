<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Services;

use Automattic\WooCommerce\Client;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Client as WooCommerceClient;

class WooCommerce
{
    public Client $client;

    public function __construct(
        protected Apps $app
    ) {
        $this->client = (new WooCommerceClient($this->app))->getClient();
    }

    public function getProducts(): array
    {
        return (array) $this->client->get('products');
    }

    public function getUsers(): array
    {
        $response = $this->client->get('/wp-json/custom/v1/users');
        $users = json_decode($response->getBody()->getContents(), true);

        return $users;
    }
}
