<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Importers;

use Kanvas\Connectors\Shopify\Actions\SyncShopifyCustomerAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Override;

/**
 * Each Shopify customer becomes a People, matched again on its SHOPIFY_CUSTOMER_ID.
 */
class ShopifyCustomersImporter extends ShopifyImporter
{
    /**
     * Only what SyncShopifyCustomerAction reads: the list is held in memory and cached between chunks,
     * and a store can have tens of thousands of customers.
     */
    #[Override]
    public function list(array $options): array
    {
        return $this->allPages('Customer', ['fields' => 'id,first_name,last_name,email,phone,addresses']);
    }

    #[Override]
    public function importOne(mixed $item, array $options): ImportOutcomeEnum
    {
        if ($this->itemId($item) === '') {
            throw new ValidationException('Shopify customer is missing an id');
        }

        $people = new SyncShopifyCustomerAction(
            $this->app(),
            $this->company(),
            $this->region(),
            $item
        )->execute();

        return $people->wasRecentlyCreated ? ImportOutcomeEnum::CREATED : ImportOutcomeEnum::UPDATED;
    }

    #[Override]
    public function itemId(mixed $item): string
    {
        return is_array($item) && is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
    }
}
