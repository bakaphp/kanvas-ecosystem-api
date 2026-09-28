<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Actions;

use Illuminate\Database\Connection;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Maps a Kanvas Product (+ its first variant/attributes) into a dealer-api `vehicles` row shape.
 *
 * Attribute source split mirrors dealer-api's own VehiclesTask::migrateVehiclesAction() output:
 * make/model/year/trim/colors/etc. live on the PRODUCT-level attributes, while `date_in_stock` only
 * exists on the VARIANT-level attributes.
 */
class MapProductToVehicleAction
{
    public function __construct(
        private Products $product,
        private Variants $variant,
        private int $rooftopId,
        private Connection $dealerConnection,
    ) {
    }

    /**
     * @return array{vehicle: array, equipment: array, photos: array, vehicle_type_ids: array}
     */
    public function execute(): array
    {
        $productAttrs = $this->product->attributes()->get()->pluck('value', 'slug');
        $variantAttrs = $this->variant->attributes()->get()->pluck('value', 'slug');
        $channel = $this->variant->variantChannels->first();

        $sku = $this->variant->sku ?: $this->product->slug;
        // dealer-api's `vin` column is a real VIN (varchar(17)); truncate longer test SKUs to fit.
        $vin = substr($sku, 0, 17);

        $vehicle = [
            'vin' => $vin,
            'valid_vin' => 1,
            'stock_number' => $sku,
            'title' => $this->product->name,
            'description' => $this->product->description,
            'year' => (string) ($productAttrs['year'] ?? ''),
            'make' => (string) ($productAttrs['make'] ?? ''),
            'model' => (string) ($productAttrs['model'] ?? ''),
            'trim' => $productAttrs['trim'] ?? null,
            'transmission' => $this->resolveTransmissionId($productAttrs['transmission'] ?? null),
            'transmission_description' => $productAttrs['transmission'] ?? null,
            'body_style' => $productAttrs['body'] ?? null,
            'exterior_color' => $productAttrs['exterior_color'] ?? null,
            'interior_color' => $productAttrs['interior_color'] ?? null,
            'exterior_generic_color' => $productAttrs['exterior_color'] ?? null,
            'interior_generic_color' => $productAttrs['interior_color'] ?? null,
            'engine_description' => $productAttrs['engine'] ?? null,
            'engine_type' => $productAttrs['engine_type'] ?? null,
            'fuel_type' => $productAttrs['fuel_type'] ?? null,
            'door_count' => $productAttrs['door_count'] ?? null,
            'milleage' => (int) ($productAttrs['milleage'] ?? 0),
            'city_miles_per_gallon' => $productAttrs['city_miles_per_gallon'] ?? null,
            'highway_miles_per_gallon' => $productAttrs['highway_miles_per_gallon'] ?? null,
            'internal_final_price' => (float) ($productAttrs['internal_final_price'] ?? 0),
            'days_stock' => $variantAttrs['date_in_stock'] ?? now()->toDateString(),
            'condition' => ($productAttrs['new'] ?? null) === 'new' ? 1 : 2,
            'sale_price' => (float) ($channel->price ?? 0),
            // dealer-api has no separate "internet price" concept in kanvas (single channel price) —
            // reuse sale_price, since AddVehicleWizard requires the key to be present.
            'internet_price' => (float) ($channel->price ?? 0),
            'msrp' => (float) ($channel->discounted_price ?? 0),
            // kanvas has no per-lot location field; use the fulfilling warehouse's name as the closest signal.
            'location' => substr((string) ($channel?->warehouse?->name ?? $channel?->productVariantWarehouse?->warehouse?->name ?? ''), 0, 45) ?: null,
            'rooftop_id' => $this->rooftopId,
            'archived' => 0,
            'is_deleted' => 0,
            'locked' => 0,
            'locked_by_vin' => 0,
            'status' => 11,
        ];

        return [
            'vehicle' => $vehicle,
            'equipment' => $this->mapEquipment($variantAttrs['equipments'] ?? []),
            'photos' => $this->mapPhotos(),
            'vehicle_type_ids' => $this->matchVehicleTypeIds(),
        ];
    }

    private function resolveTransmissionId(?string $name): int
    {
        if ($name === null) {
            return 1;
        }

        return (int) ($this->dealerConnection->table('transmission')
            ->where('name', $name)
            ->value('id') ?? 1);
    }

    /**
     * Reverses dealer-api's own getEquipmentAttributes() shape (category/description/is_custom/
     * is_highlight/opt_code) back into `vehicle_equipment` row fields.
     */
    private function mapEquipment(mixed $equipments): array
    {
        if (! is_array($equipments)) {
            return [];
        }

        return array_map(fn (array $item) => [
            'equipment_id' => null,
            'is_custom' => (int) ($item['is_custom'] ?? 1),
            'is_on' => 1,
            'opt_code' => $item['opt_code'] ?? null,
            'custom_description' => $item['description'] ?? null,
            'is_highlight' => (int) ($item['is_highlight'] ?? 0),
            'category' => (int) ($item['category'] ?? 0),
            'is_deleted' => 0,
        ], $equipments);
    }

    /**
     * `vehicle_media.name`/`dir` (varchar(45)/varchar(100)) are sized for a local filename, not an
     * absolute CDN URL, and dealer-api's own getOriginalUrl() always concatenates base_url+dir+name+ext
     * — it has no concept of an external URL. Without a real download/resize/upload pipeline into
     * dealer-api's own media storage, these rows won't render through dealer-api's UI. We still insert
     * them (URL best-effort in `real_name`, the largest available field) so the reference isn't lost
     * and can be reprocessed later — not a substitute for a real image migration.
     */
    private function mapPhotos(): array
    {
        $files = $this->variant->files->isNotEmpty() ? $this->variant->files : $this->product->files;

        return $files->values()->map(fn ($file, int $index) => [
            'type' => 1, // VehicleMedia::IMAGES
            'is_cover' => $index === 0 ? 1 : 0,
            'name' => substr((string) $file->name, 0, 45),
            'ext' => substr((string) pathinfo($file->name, PATHINFO_EXTENSION), 0, 10),
            'real_name' => substr((string) $file->url, 0, 100),
            'position' => $index,
            'retake' => 0,
            'is_stock' => 0,
            'is_deleted' => 0,
        ])->all();
    }

    /**
     * dealer-api's `vehicle_types` is a small fixed catalog (Hybrid, Luxury, Electric, AWD/4WD, Sport)
     * — not a general body-type list. Kanvas has no dedicated equivalent field, so match it against
     * the product's category names (case-insensitive) as the closest available signal.
     */
    private function matchVehicleTypeIds(): array
    {
        $categoryNames = $this->product->categories
            ->map(fn ($category) => strtolower(trim((string) $category->name)))
            ->all();

        if ($categoryNames === []) {
            return [];
        }

        return $this->dealerConnection->table('vehicle_types')
            ->get()
            ->filter(fn ($type) => in_array(strtolower(trim((string) $type->name)), $categoryNames, true))
            ->pluck('id')
            ->all();
    }
}
