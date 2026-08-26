<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Sources;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\Yusen\Contracts\InventoryQuantitySource;
use Kanvas\Connectors\Yusen\Services\YusenSettings;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * The ERP book count at the location Yusen holds, read live from NetSuite.
 *
 * Keyed on `itemId`, which is the barcode Yusen's `<Item>` carries, so the two line up without a
 * mapping table.
 *
 * The search must be joined to an inventory location or NetSuite returns a null quantity on every
 * row regardless of the saved search id — verified against search 574: no location gives null,
 * location 7 gives 1221 for the same SKU. Note the figure is quantity **available** (on hand minus
 * committed), so a fully-committed location reads as nothing available rather than as its on-hand.
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
        $locationId = $this->settings->netSuiteLocationId();

        if ($locationId === null) {
            throw new ValidationException(
                'yusen_netsuite_location_id is not set. NetSuite only returns stock when the search '
                . 'is joined to an inventory location; without it every row comes back with a null '
                . 'quantity and no comparison is possible.'
            );
        }

        $products = new NetSuiteProductSearchService($this->app, $this->company)
            ->searchByLocation($locationId, $this->settings->netSuiteSavedSearchId());

        return $this->indexQuantities($products, $locationId);
    }

    /**
     * Turns raw search rows into item => available. Split from the search call so the row
     * semantics — which are the subtle part — can be tested without reaching NetSuite.
     *
     * @param array<array-key, mixed> $products
     * @return array<string, float>
     */
    protected function indexQuantities(array $products, string $locationId): array
    {
        $savedSearchId = $this->settings->netSuiteSavedSearchId();
        $quantities = [];
        $rows = 0;
        $withoutQuantity = 0;

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $itemId = $product['itemId'] ?? null;

            if ($itemId === null) {
                continue;
            }

            $item = (string) $itemId;
            $rows++;

            if (isset($product['displayName'])) {
                $this->names[$item] = (string) $product['displayName'];
            }

            // NetSuite omits `locationQuantityAvailable` rather than sending 0 when nothing is
            // available at the joined location — verified on Aero, which holds 741 with all 741
            // committed and comes back with the field absent. Because the search always joins a
            // location, an absent value on a returned row is a real "zero available", not missing
            // data, and must still be compared: Yusen holding stock NetSuite has none of is the
            // case most worth reporting.
            if (! isset($product['quantityAvailable'])) {
                $withoutQuantity++;
            }

            $quantities[$item] = (float) ($product['quantityAvailable'] ?? 0);
        }

        // Every row absent is the one shape that means misconfiguration rather than empty stock:
        // a whole location with nothing available anywhere is not a real inventory position.
        if ($rows > 0 && $withoutQuantity === $rows) {
            throw new ValidationException(
                'NetSuite saved search ' . $savedSearchId . ' at location ' . $locationId
                . ' returned ' . $rows . ' rows but no locationQuantityAvailable on any of them, '
                . 'so no stock comparison is possible.'
            );
        }

        if ($withoutQuantity > 0) {
            Log::info('Yusen.NetSuiteSource — rows read as zero available at this location', [
                'saved_search_id' => $savedSearchId,
                'location_id' => $locationId,
                'rows' => $rows,
                'without_quantity' => $withoutQuantity,
            ]);
        }

        return $quantities;
    }

    #[Override]
    public function describe(string $item): ?string
    {
        return $this->names[$item] ?? null;
    }
}
