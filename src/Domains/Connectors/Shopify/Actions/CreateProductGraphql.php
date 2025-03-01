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
use Kanvas\Connectors\Shopify\Actions\PublishProductGraphqlAction;

// to do: rename to standard push product graphql
// to do: create variant into the method execute
class CreateProductGraphql
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

        try {
            $graphQL = <<<QUERY
            mutation productCreate(\$product: ProductCreateInput, \$media: [CreateMediaInput!]) {
                productCreate(product: \$product, media: \$media) {
                    product {
                        id,
                        featuredMedia {
                            id,
                            preview {
                                image {
                                    url
                                }
                            }
                        },
                        media(first:10) {
                            nodes {
                                id,
                            },
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            QUERY;
            $filesystems = $this->products->getFiles();
            $media = [];
            foreach ($filesystems as $filesystem) {
                $media[] = [
                    'originalSource' => $filesystem->url,
                    'alt' => $filesystem->name,
                    'mediaContentType' => 'IMAGE',
                ];
            }

            $variables = [
                "product" => [
                    "title" => $this->products->name,
                    "descriptionHtml" => $this->products->description,
                ],
            ];
            if (! empty($this->metafields)) {
                $variables['product']['metafields'] = $this->metafields;
            }
            if (! empty($media)) {
                $variables['media'] = $media;
            }
            $client = Client::getInstance($this->app, $this->branch->company, $this->warehouse->regions);
            $response = $client->GraphQL->post($graphQL, null, null, $variables);
            $id = $response['data']['productCreate']['product']['id'];
            $id = basename($id);
            $this->products->setShopifyId($this->warehouse->regions, $id);
            (new PublishProductGraphqlAction(
                $this->app, 
                $this->branch,
                $this->warehouse,
                $this->products
            ))->execute();
        } catch (\Throwable $e) {
            Log::error('ShopifySaveAction failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
        return $response['data']['productCreate']['product'] ?? ['error' => 'Unknown error'];
    }
}
