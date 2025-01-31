<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Enums\WooCommerceEnum;
use Automattic\WooCommerce\Client;

class WooCommerce
{
    public Client $client;
    public function __construct(
        protected Apps $app
    ) {
        $wooCommerceUrl = $this->app->get(WooCommerceEnum::WORDPRESS_URL->value);
        $wooCommerceKey = $this->app->get(WooCommerceEnum::WOOCOMMERCE_KEY->value);
        $wooCommerceSecretKey = $this->app->get(WooCommerceEnum::WOOCOMMERCE_SECRET_KEY->value);
        $this->client = new Client(
            $wooCommerceUrl,
            $wooCommerceKey,
            $wooCommerceSecretKey,
            [
                'version' => 'wc/v3',
            ]
        );
    }

    public function getProducts(): array
    {
        return $this->client->get('products');
    }

    public function getUsers(): array
    {
        $response = $this->client->get('/wp-json/custom/v1/users');
        $users = json_decode($response->getBody()->getContents(), true);
        return $users;
    }
}
