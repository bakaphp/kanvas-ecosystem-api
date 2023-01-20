<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Shopify;

use GuzzleHttp\Client as GuzzleClient;

class Client
{    
    /**
     * getClient
     *
     * @param  string $storeUrl
     * @return GuzzleClient
     */
    public static function getClient(string $storeUrl): GuzzleClient
    {
        return new GuzzleClient([
            'base_uri' => "$storeUrl/admin/api/2023-01/graphql.json",
            'headers' => [
                'X-Shopify-Access-Token' => config('shopify.access_token'),
            ]
        ]);
    }
}
