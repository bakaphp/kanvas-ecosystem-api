<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Shopify\Mutations;

use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\ShopifyService;

class Shopify
{

    public function shopifySetup(mixed $root, array $request): bool
    {
        $shopifyDto = ShopifyDto::viaRequest($request['input']);

        return ShopifyService::shopifySetup($shopifyDto);
    }
}
