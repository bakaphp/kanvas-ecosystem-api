<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Services;

use Automattic\WooCommerce\Client;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Client as WooCommerceClient;

class WooCommerceProductService
{
    public Client $client;

    public function __construct(
        protected Apps $app
    ) {
        $this->client = (new WooCommerceClient($this->app))->getClient();
    }

    public function getProductDataBySku(string $sku): array
    {
        $productData = [
            'id'   => 0,
            'name' => 'Product '.$sku,
        ];

        $products = $this->client->get('products', [
            'sku'      => $sku,
            'per_page' => 1, // Limit to 1 result for efficiency
        ]);

        // If we found a matching product, return its ID and name
        if (! empty($products) && is_array($products)) {
            $productData['id'] = (int) $products[0]->id;
            $productData['name'] = $products[0]->name ?? ('Product '.$sku);
        }

        return $productData;
    }
}
