<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportProduct;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Kanvas\Users\Models\Users;

/**
 * Pull on-hand stock from Acumatica `dbo.INSiteStatus` into
 * products_variants_warehouses. Joins InventoryItem (SKU) + INSite (warehouse) so rows
 * arrive as (sku, warehouse, qty). The variant, the warehouse, and the variant↔warehouse
 * link are all created on the fly if missing — so stock actually lands even when the
 * product was imported against a different (default) warehouse.
 */
class PullStockAction
{
    /** @var array<int, array<string, mixed>> per-row diagnostics (printed by the command under --debug) */
    public array $log = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected KanvasRegions $region,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchRows());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchRows(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('INSiteStatus as s')
            ->join('InventoryItem as i', function (JoinClause $join): void {
                $join->on('i.InventoryID', '=', 's.InventoryID')
                    ->on('i.CompanyID', '=', 's.CompanyID');
            })
            ->join('INSite as w', function (JoinClause $join): void {
                $join->on('w.SiteID', '=', 's.SiteID')
                    ->on('w.CompanyID', '=', 's.CompanyID');
            })
            ->where('s.CompanyID', $this->acumaticaCompanyId)
            ->select([
                'i.InventoryCD as sku',
                'w.SiteCD as warehouse',
                'w.Descr as warehouse_desc',
                's.QtyAvail as qty',
                's.QtyOnHand as on_hand',
            ])
            // highest on-hand first so a limited run surfaces items that actually have stock
            ->orderByDesc('s.QtyAvail');

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows each: sku, warehouse, qty
     */
    public function processRows(array $rows): int
    {
        $count = 0;
        $this->log = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $warehouseCode = trim((string) ($row['warehouse'] ?? ''));
            $acuQty = (float) ($row['qty'] ?? 0);

            if ($sku === '' || $warehouseCode === '') {
                continue;
            }

            $variant = $this->ensureVariant($sku);
            $warehouse = $this->ensureWarehouse($warehouseCode, trim((string) ($row['warehouse_desc'] ?? '')));

            $entry = [
                'sku' => $sku,
                'warehouse' => $warehouseCode,
                'acu_qty' => $acuQty,
                'variant_id' => $variant?->getId(),
                'warehouse_id' => $warehouse?->getId(),
            ];

            if ($variant === null || $warehouse === null) {
                $entry['result'] = 'skip:' . ($variant === null ? 'no-variant' : 'no-warehouse');
                $this->log[] = $entry;

                continue;
            }

            $existed = $variant->variantWarehouses()->where('warehouses_id', $warehouse->getId())->exists();
            $this->upsertStock($variant, $warehouse, $acuQty);

            $vw = $variant->variantWarehouses()->where('warehouses_id', $warehouse->getId())->first();
            $entry['result'] = $existed ? 'updated' : 'created';
            $entry['final_qty'] = $vw?->quantity;
            $entry['final_price'] = $vw?->price;
            $this->log[] = $entry;

            $count++;
        }

        return $count;
    }

    /**
     * Set quantity on the variant↔warehouse row, creating it if it doesn't exist yet
     * (updateQuantityInWarehouse alone only touches existing rows). Preserves the
     * existing price; new rows inherit the variant's current price.
     */
    private function upsertStock(Variants $variant, Warehouses $warehouse, float $qty): void
    {
        $existing = $variant->variantWarehouses()->where('warehouses_id', $warehouse->getId())->first();

        if ($existing !== null) {
            $variant->updateQuantityInWarehouse($warehouse, $qty);

            return;
        }

        $price = (float) ($variant->variantWarehouses()->value('price') ?? 0);

        new AddToWarehouseAction(
            $variant,
            $warehouse,
            new VariantsWarehousesDto(
                variant: $variant,
                warehouse: $warehouse,
                quantity: $qty,
                price: $price,
                sku: (string) $variant->sku,
            ),
        )->execute();
    }

    private function ensureVariant(string $sku): ?Variants
    {
        /** @var Variants|null $variant */
        $variant = Variants::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('sku', $sku)
            ->notDeleted()
            ->first();

        if ($variant !== null) {
            return $variant;
        }

        $row = $this->fetchItemRow($sku);

        if ($row === null) {
            return null;
        }

        new ProductImporterAction(
            AcumaticaImportProduct::fromRow($row),
            $this->company,
            $this->user,
            $this->region,
            $this->app,
            false,
        )->execute();

        /** @var Variants|null $variant */
        $variant = Variants::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('sku', $sku)
            ->notDeleted()
            ->first();

        return $variant;
    }

    private function ensureWarehouse(string $code, string $descr): ?Warehouses
    {
        /** @var Warehouses|null $warehouse */
        $warehouse = Warehouses::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('name', $code)
            ->notDeleted()
            ->first();

        if ($warehouse !== null) {
            return $warehouse;
        }

        return new CreateWarehouseAction(
            new WarehousesDto(
                company: $this->company,
                app: $this->app,
                user: $this->user,
                region: $this->region,
                name: $code,
                location: $descr !== '' ? $descr : null,
            ),
            $this->user,
        )->execute();
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function fetchItemRow(string $sku): ?array
    {
        $row = SqlClient::connection($this->app)
            ->table('InventoryItem')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->where('InventoryCD', $sku)
            ->first();

        return $row !== null ? (array) $row : null;
    }
}
