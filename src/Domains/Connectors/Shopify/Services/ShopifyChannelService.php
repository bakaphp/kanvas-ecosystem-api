<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use PHPShopify\ShopifySDK;

use function Sentry\captureException;

use Throwable;

class ShopifyChannelService
{
    protected ShopifySDK $shopifySdk;
    protected ShopifyInventoryService $inventoryService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Warehouses $warehouses,
    ) {
        $this->shopifySdk = Client::getInstance($app, $company, $warehouses->regions);
        $this->inventoryService = new ShopifyInventoryService($app, $company, $warehouses);
    }

    public function getAvailablePublicationChannels(): array
    {
        try {
            $graphql = <<<QUERY
        {
          publications(first: 250) {
            edges {
              node {
                id
                name
                supportsFuturePublishing
              }
            }
          }
        }
        QUERY;

            $response = $this->shopifySdk->GraphQL->post($graphql);

            return $response['data']['publications']['edges'] ?? [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function addToPublicationChannel(Products $product, ?string $publicationId = null): array
    {
        try {
            // Get all parts of the product
            $variantLimit = $this->app->get(ConfigEnum::VARIANT_LIMIT->value, 99);
            $productParts = $this->inventoryService->prepareProductParts($product, $variantLimit);
            $responses = [];

            // If no publication ID provided, try to get the Online Store channel
            if ($publicationId === null) {
                $publicationId = $this->getOnlineStorePublicationId();
            }

            foreach ($productParts as $part) {
                $partNumber = $part['part_number'];
                $shopifyProductIdPartNumber = $partNumber > 1 ? "-part-{$partNumber}" : null;
                $shopifyProductId = $product->getShopifyId($this->warehouses->regions, $shopifyProductIdPartNumber);

                if ($shopifyProductId !== null) {
                    continue;
                }

                $graphql = <<<QUERY
            mutation publishablePublish(\$id: ID!, \$input: [PublicationInput!]!) {
              publishablePublish(id: \$id, input: \$input) {
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

                $variables = [
                    'id' => 'gid://shopify/Product/' . $shopifyProductId,
                    'input' => [
                        'publicationId' => $publicationId,
                    ],
                ];

                $response = $this->shopifySdk->GraphQL->post($graphql, null, null, $variables);
                $responses[] = $response;
            }

            return count($responses) > 1 ? $responses : $responses[0] ?? [];
        } catch (Throwable $e) {
            Log::error("Failed to add product {$product->id} to publication: " . $e->getMessage());
            captureException($e);

            return ['error' => $e->getMessage()];
        }
    }

    public function removeFromPublicationChannel(Products $product, ?string $publicationId = null): array
    {
        try {
            // Get all parts of the product
            $variantLimit = $this->app->get(ConfigEnum::VARIANT_LIMIT->value, 99);
            $productParts = $this->inventoryService->prepareProductParts($product, $variantLimit);
            $responses = [];

            // If no publication ID provided, try to get the Online Store channel
            if ($publicationId === null) {
                $publicationId = $this->getOnlineStorePublicationId();
            }

            foreach ($productParts as $part) {
                $partNumber = $part['part_number'];
                $shopifyProductIdPartNumber = $partNumber > 1 ? "-part-{$partNumber}" : null;
                $shopifyProductId = $product->getShopifyId($this->warehouses->regions, $shopifyProductIdPartNumber);

                if ($shopifyProductId !== null) {
                    continue;
                }

                $graphql = <<<QUERY
            mutation publishableUnpublish(\$id: ID!, \$input: [PublicationInput!]!) {
              publishableUnpublish(id: \$id, input: \$input) {
                publishable {
                  availablePublicationCount
                  publicationCount
                }
                shop {
                  publicationCount
                }
                userErrors {
                  field
                  message
                }
              }
            }
            QUERY;

                $variables = [
                    'id' => 'gid://shopify/Product/' . $shopifyProductId,
                    'input' => [
                        'publicationId' => $publicationId,
                    ],
                ];

                $response = $this->shopifySdk->GraphQL->post($graphql, null, null, $variables);
                $responses[] = $response;
            }

            return count($responses) > 1 ? $responses : $responses[0] ?? [];
        } catch (Throwable $e) {
            Log::error("Failed to remove product {$product->id} from publication: " . $e->getMessage());
            captureException($e);

            return ['error' => $e->getMessage()];
        }
    }

    protected function getOnlineStorePublicationId(): string
    {
        // Try to get from config first gid://shopify/Publication/99888840
        $defaultPublicationId = $this->app->get(ConfigEnum::SHOPIFY_PUBLICATION_ID->value);
        if ($defaultPublicationId) {
            return 'gid://shopify/Publication/' . $defaultPublicationId;
        }

        // Otherwise, fetch and look for 'Online Store'
        $publications = $this->getAvailablePublicationChannels();
        foreach ($publications as $publication) {
            if (trim(strtolower($publication['node']['name'])) === 'online store') {
                return $publication['node']['id'];
            }
        }

        // Fallback to a default ID if nothing found
        return 'gid://shopify/Publication/1';
    }
}
