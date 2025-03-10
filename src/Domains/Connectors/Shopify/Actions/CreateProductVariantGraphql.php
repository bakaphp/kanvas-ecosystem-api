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
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use PHPShopify\ShopifySDK;

// to do: rename to standard push variant product graphql

class CreateProductVariantGraphql
{
    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch,
        protected Warehouses $warehouse,
        protected Products $products,
    ) {
    }

    public function execute(): array
    {
        $variants = $this->products->variants;
        $variantsResponses = [];
        $client = Client::getInstance($this->app, $this->branch->company, $this->warehouse->regions);

        try {
            foreach ($variants as $variant) {
                $price = $variant->getPrice($this->warehouse);
                $productVariantId = $this->products->getShopifyId($this->warehouse->regions);
                $variantShopifyId = $variant->getShopifyId($this->warehouse->regions);
                if (! $variantShopifyId) {
                    $graphql = <<<QUERY
                    mutation productVariantsBulkCreate(\$variants: [ProductVariantsBulkInput!]!, \$productId: ID!) {
                        productVariantsBulkCreate(productId: \$productId, variants: \$variants) {
                            productVariants {
                              id
                              title
                              price,
                            }
                            userErrors {
                              field
                              message
                            }
                        }
                    }
                    QUERY;
                } else {
                    $graphql = <<<QUERY
                    mutation productVariantsBulkUpdate(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
                        productVariantsBulkUpdate(productId: \$productId, variants: \$variants) {
                            productVariants {
                              id
                              title
                              price
                            }
                            userErrors {
                              field
                              message
                            }
                        }
                    }
                    QUERY;
                }
                $variables = [
                    "variants" => [
                        'price' => $price,
                        'optionValues' => [
                            [
                                'name' => $variant->name,
                                'optionName' => 'Title',
                            ]
                        ],
                        'inventoryItem' => [
                            'tracked' => true,
                            'sku' => $variant->sku,
                            'measurement' => [
                                'weight' => [
                                    'unit' => 'GRAMS',
                                    'value' => $variant->get(ConfigurationEnum::WEIGHT_UNIT->value)
                                ]
                            ]
                        ],
                        'inventoryQuantities' => [
                            [
                                'locationId' => $this->getShopifyLocationId($client),
                                'availableQuantity' => $variant->getQuantity($this->warehouse),
                            ]
                        ]
                    ],
                    'productId' => "gid://shopify/Product/{$productVariantId}",
                ];
                if ($variantShopifyId) {
                    $variables['variants']['id'] = "gid://shopify/ProductVariant/" . $variantShopifyId;
                    unset($variables['variants']['optionValues']);
                    unset($variables['variants']['inventoryQuantities']);
                }
                $response = $client->GraphQL->post($graphql, null, null, $variables);
                $id = $variantShopifyId ? $response['data']['productVariantsBulkUpdate']['productVariants'][0]['id'] : $response['data']['productVariantsBulkCreate']['productVariants'][0]['id'];
                $id = basename($id);
                $variant->setShopifyId($this->warehouse->regions, $id);
                $variantsResponses[] = $response;
            }
        } catch (\Throwable $e) {
            Log::error('CreateProductVariantGraphql failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
        return $variantsResponses;
    }

    protected function getShopifyLocationId(ShopifySDK $client): string
    {
        $query = <<<QUERY
        {
            locations(first: 1) {
                edges {
                    node {
                        id
                        name
                    }
                }
            }
        }
        QUERY;
        $response = $client->GraphQL->post($query);
        return $response['data']['locations']['edges'][0]['node']['id'];
    }
}
