<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Shopify\Clients\Graphql;

// to do: rename to standard push product graphql
class UpdateProductGraphql
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
            mutation UpdateProductWithNewMedia(\$product: ProductUpdateInput, \$media: [CreateMediaInput!]) {
                productUpdate(product: \$product, media: \$media) {
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
            $id = $this->products->getShopifyId($this->warehouse->regions);
            $variables = [
                "product" => [
                    "title" => $this->products->name,
                    "descriptionHtml" => $this->products->description,
                    "id" => "gid://shopify/Product/{$id}",
                    'handle' => $this->products->slug,
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
            $id = $response['data']['productUpdate']['product']['id'];
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
