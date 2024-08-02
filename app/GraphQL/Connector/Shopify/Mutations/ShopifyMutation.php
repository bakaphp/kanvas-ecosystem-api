<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Shopify\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\DataTransferObject\Shopify as ShopifyDto;
use Kanvas\Connectors\Shopify\ShopifyService;
use Kanvas\Users\Repositories\UsersRepository;

class ShopifyMutation
{
    public function shopifySetup(mixed $root, array $request): String
    {
        $user = auth()->user();
        $company = isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany();
        $app = app(Apps::class);

        UsersRepository::belongsToCompany($user, $company);

        $shopifyDto = ShopifyDto::viaRequest($request['input'], $app, $company);

        Client::getInstance($app, $company, $shopifyDto->region)->Shop->get();
        ShopifyService::shopifySetup($shopifyDto);

        return "Shopify Integration Successfully";
    }
}
