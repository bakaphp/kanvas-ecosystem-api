<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Sources;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\Yusen\Contracts\InventoryQuantitySource;
use Kanvas\Connectors\Yusen\Services\YusenSettings;
use Override;

/**
 * The ERP book count, read live from a NetSuite saved search (`locationQuantityAvailable`).
 *
 * Same saved search `PullNetSuiteProductStockAction` uses, and keyed on the same `itemId` — which
 * is the barcode Yusen's `<Item>` carries, so the two line up without a mapping table.
 */
class NetSuiteSavedSearchQuantitySource implements InventoryQuantitySource
{
    /** @var array<string, string> */
    private array $names = [];

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly YusenSettings $settings,
    ) {
    }

    #[Override]
    public function key(): string
    {
        return 'netsuite';
    }

    #[Override]
    public function quantities(): array
    {
        $products = new NetSuiteProductSearchService($this->app, $this->company)
            ->searchWithSavedSearch($this->settings->netSuiteSavedSearchId());

        $quantities = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $itemId = $product['itemId'] ?? null;

            if ($itemId === null) {
                continue;
            }

            $item = (string) $itemId;
            $quantities[$item] = (float) ($product['quantityAvailable'] ?? 0);

            if (isset($product['displayName'])) {
                $this->names[$item] = (string) $product['displayName'];
            }
        }

        return $quantities;
    }

    #[Override]
    public function describe(string $item): ?string
    {
        return $this->names[$item] ?? null;
    }
}
