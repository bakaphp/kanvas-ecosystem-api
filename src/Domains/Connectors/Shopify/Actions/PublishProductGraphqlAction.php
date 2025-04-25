<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Throwable;

class PublishProductGraphqlAction
{
    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch,
        protected Warehouses $warehouse,
        protected Products $products,
        public array $metafields = []
    ) {
    }

    public function execute(): void
    {
        try {
            $client = Client::getInstance($this->app, $this->branch->company, $this->warehouse->regions);

            $graphql = <<<QUERY
            {
              publications(first: 10) {
                edges {
                  node {
                    id
                    name
                  }
                }
              }
            }
            QUERY;
            $response = $client->GraphQL->post($graphql);
            foreach ($response['data']['publications']['edges'] as $publication) {
                $publicationId = $publication['node']['id'];

                $graphql = <<<QUERY
                mutation productPublish(\$input: ProductPublishInput!) {
                  productPublish(input: \$input) {
                        userErrors {
                            field
                            message
                        }                  
                  }
                }
                QUERY;

                $productId = $this->products->getShopifyId($this->warehouse->regions);
                $variables = [
                    "input" => [
                        "id" => "gid://shopify/Product/{$productId}",
                        'productPublications' => [
                            "publicationId" => $publicationId,
                        ]
                    ]
                ];
                $response = $client->GraphQL->post($graphql, null, null, $variables);
                // if ($response['data']['productPublish']['userErrors']) {
                //     throw new Exception($response['userErrors'][0]['message']);
                // }
            }
        } catch (Throwable $e) {
            throw new Exception($e->getMessage());
        }
    }
}
