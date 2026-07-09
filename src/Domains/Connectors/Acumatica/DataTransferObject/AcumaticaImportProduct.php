<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Illuminate\Support\Str;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;

/**
 * Maps a raw Acumatica `dbo.InventoryItem` row (read from the SQL replica) into the
 * shared ProductImporter shape. Acumatica items are single-SKU (color is a distinct
 * InventoryCD, not a sub-item), so each item → one product + one variant with the same SKU.
 * On-hand quantity is synced separately from `INSiteStatus` (PullStockAction) — variants
 * are created here with quantity 0 and their list price.
 */
class AcumaticaImportProduct extends ProductImporter
{
    public const string SOURCE = 'acumatica';

    /**
     * @param array<array-key, mixed> $row raw InventoryItem row (PascalCase columns)
     */
    public static function fromRow(array $row): self
    {
        $sku = trim((string) ($row['InventoryCD'] ?? ''));
        $name = trim((string) ($row['Descr'] ?? '')) ?: $sku;
        $price = (float) ($row['BasePrice'] ?? 0);
        $weight = (float) ($row['BaseItemWeight'] ?? ($row['BaseWeight'] ?? 0));
        $inventoryId = (string) ($row['InventoryID'] ?? '');
        // Acumatica ItemStatus: 'AC' = Active. Anything else (Inactive/NoSales/…) → unpublished.
        $isPublished = strtoupper((string) ($row['ItemStatus'] ?? 'AC')) === 'AC';

        $customFields = [
            ['name' => CustomFieldEnum::PRODUCT_ID->value, 'data' => $inventoryId],
        ];

        if (! empty($row['NoteID'])) {
            $customFields[] = ['name' => CustomFieldEnum::NOTE_ID->value, 'data' => (string) $row['NoteID']];
        }

        $files = [];
        if (! empty($row['ImageUrl'])) {
            $files[] = ['name' => $sku, 'url' => (string) $row['ImageUrl']];
        }

        return new self(
            name: $name,
            slug: Str::slug($sku),
            sku: $sku,
            variants: [
                [
                    'name' => $name,
                    'sku' => $sku,
                    'price' => $price,
                    'quantity' => 0,
                    'source_id' => $inventoryId,
                ],
            ],
            isPublished: $isPublished,
            upc: ! empty($row['UsrUPC']) ? (string) $row['UsrUPC'] : null,
            source: self::SOURCE,
            sourceId: $inventoryId,
            status: (string) ($row['ItemStatus'] ?? ''),
            files: $files,
            customFields: $customFields,
            weight: $weight > 0 ? $weight : null,
            warehouses: [],
        );
    }
}
