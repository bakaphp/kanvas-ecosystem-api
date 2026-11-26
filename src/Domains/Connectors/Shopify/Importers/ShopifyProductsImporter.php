<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Importers;

use Kanvas\Connectors\Shopify\Actions\SyncShopifyProductAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Override;

/**
 * Every product lands in the default warehouse of the integration's region, as the product webhook
 * does. Workflows stay off: the product sync workflow would push each product straight back to Shopify.
 */
class ShopifyProductsImporter extends ShopifyImporter
{
    /**
     * Without these every product would fail on the same lookup, one by one.
     */
    #[Override]
    public function prepare(array $options): array
    {
        $region = $this->region();

        if (! Warehouses::fromCompany($this->company())->where('is_default', 1)->where('regions_id', $region->getId())->exists()) {
            throw new ValidationException('The region ' . $region->name . ' has no default warehouse to import Shopify products into.');
        }

        if (! Channels::fromCompany($this->company())->where('is_default', 1)->exists()) {
            throw new ValidationException('The company has no default channel to import Shopify products into.');
        }

        return $options;
    }

    /**
     * Ids only: a full product carries its HTML and images, and the list is cached between chunks.
     */
    #[Override]
    public function list(array $options): array
    {
        return array_map(
            fn (array $product) => (string) $product['id'],
            $this->allPages('Product', ['fields' => 'id'])
        );
    }

    #[Override]
    public function importOne(mixed $item, array $options): ImportOutcomeEnum
    {
        $productId = (string) $item;

        $product = new SyncShopifyProductAction(
            app: $this->app(),
            company: $this->company(),
            region: $this->region(),
            productId: $productId,
            user: $this->user,
            runWorkflow: false,
        )->import($this->shopifyProduct($productId));

        return $product->wasRecentlyCreated ? ImportOutcomeEnum::CREATED : ImportOutcomeEnum::UPDATED;
    }

    protected function shopifyProduct(string $productId): array
    {
        return $this->shopify()->Product($productId)->get();
    }
}
