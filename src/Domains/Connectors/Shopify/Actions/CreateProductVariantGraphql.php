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

class CreateProductVariantGraphql
{

    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch,
        protected Warehouses $warehouse,
        protected Products $products,
        array $metafields = []
    ) {
    }

    public function execute(): array
    {
        $variants = $this->products->variants;
        
        foreach ($variants as $variant) {
            $price = $variant->getPrice($this->warehouse);
            $graphql = <<<QUERY
            mutation createProductVariant(\$input: ProductVariantInput!) {
                productVariantCreate(input: \$input) {
                    productVariant {
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
                "input" => [
                    'productId' => $this->products->getShopifyId($this->warehouse->regions),
                    'price' => $price,
                    'options' => [
                        $variant->name
                    ]
                ]
            ];
            $client = Client::getInstance($this->app, $this->branch->company, $this->warehouse->regions);
            $response = $client->GraphQL->post($graphql, null, null, $variables);
            Log::info('CreateProductVariantGraphql successful', ['response' => $response]);
        }
    }
}
