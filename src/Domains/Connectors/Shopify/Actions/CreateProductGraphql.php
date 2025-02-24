<?php
declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Products\Models\Products;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;
use Kanvas\Connectors\Shopify\Client;

class CreateProductGraphql
{
    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch,
        protected Warehouses $warehouse,
        protected Products $products
    ) {
    }

    public function execute(): array
    {
        Log::debug("ShopifySaveAction started");

        try {
            $graphQL = <<<QUERY
            mutation productCreate(\$product: ProductCreateInput) {
                productCreate(product: \$product) {
                    product {
                        id
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            QUERY;

            $variables = [
                "product" => [
                    "title" => $this->products->name,
                    "bodyHtml" => $this->products->description,
                ]
            ];

            $client = Client::getInstance($this->app, $this->branch->company, $this->warehouse->regions);

            $response = $client->GraphQL->post($graphQL, null, null, $variables);

            Log::info('ShopifySaveAction successful', ['response' => $response]);
        } catch (\Throwable $e) {
            Log::error('ShopifySaveAction failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }

        return $response ?? ['error' => 'Unknown error'];
    }
}
