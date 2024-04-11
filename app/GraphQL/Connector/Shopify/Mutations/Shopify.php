<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Shopify\Mutations;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\ShopifyService;

class Shopify
{

    public function shopifySetup(mixed $root, array $request): bool
    {
        $company = isset($data['companies_id']) ? Companies::getById($data['companies_id']) : auth()->user()->getCurrentCompany();

        $shopifyDto = ShopifyDto::viaRequest($request['input'], $company);

        return ShopifyService::shopifySetup($shopifyDto);
    }
}
