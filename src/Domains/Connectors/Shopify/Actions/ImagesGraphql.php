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

class ImagesGraphql
{
    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch,
        protected Warehouses $warehouse,
        protected Products $products,
        public array $metafields = []
    ) {
    }

    public function execute(): array
    {
        Log::debug("ShopifySaveAction started");
        $images = [];

        try {
            $graphQL = <<<QUERY
             query product(\$ownerId: ID!){
                product(id: \$ownerId) {
                    id,
                    title,
                    descriptionHtml,
                    media(first:10) {
                        nodes {
                            id,
                            preview {
                                image {
                                    url
                                }
                            }
                        },
                    }
                }
            }
            QUERY;
            $client = Client::getInstance($this->app, $this->branch->company, $this->warehouse->regions);
            $response = $client->GraphQL->post($graphQL, null, null, [
                'ownerId' => "gid://shopify/Product/".$this->products->getShopifyId($this->warehouse->regions),
            ]);
            $imagesResponse = $response['data']['product']['media']['nodes'] ?? [];
            foreach ($imagesResponse as $image) {
                $graphQL = <<<QUERY
                query(\$id: ID!){
                    node(id: \$id) {
                        id
                        ... on MediaImage {
                          image {
                            url
                          }
                        }
                    }
                }
                QUERY;
                $response = $client->GraphQL->post($graphQL, null, null, [
                    'id' => $image['id'],
                ]);
                $images[] = $response['data']['node']['image']['url'];
            }
        } catch (\Throwable $e) {
            Log::error('ShopifySaveAction image failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
        return $images;
    }
}
