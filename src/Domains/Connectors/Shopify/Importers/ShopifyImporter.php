<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Importers;

use Kanvas\Connectors\Shopify\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Integrations\Imports\Importers\ChunkedImporter;
use PHPShopify\ShopifySDK;

/**
 * Shopify credentials are stored per region, so the integration row's region is the store being read.
 */
abstract class ShopifyImporter extends ChunkedImporter
{
    protected const int PAGE_SIZE = 250;

    protected function region(): Regions
    {
        return $this->integrationCompany->region
            ?? throw new ValidationException('This Shopify integration has no region.');
    }

    protected function shopify(): ShopifySDK
    {
        return Client::getInstance($this->app(), $this->company(), $this->region());
    }

    /**
     * Shopify pages by cursor: every request after the first carries only the page_info the previous
     * response returned.
     *
     * @return list<array>
     */
    protected function allPages(string $resource, array $params = []): array
    {
        $endpoint = $this->shopify()->{$resource}();
        $params = ['limit' => self::PAGE_SIZE, ...$params];
        $records = [];

        do {
            array_push($records, ...$endpoint->get($params));
            $params = $endpoint->getNextPageParams();
        } while (! empty($params));

        return $records;
    }
}
