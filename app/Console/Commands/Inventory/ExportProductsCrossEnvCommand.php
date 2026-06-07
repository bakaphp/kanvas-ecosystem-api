<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Export a company's inventory to a self-describing JSONL file for cross-environment migration.
 *
 * Every foreign key (warehouse / channel / status / region) is exported by **name**, not id, so the
 * companion import command can resolve them against the destination company's own ids. Run this in the
 * SOURCE environment, then hand the file to `kanvas-inventory:import-products-cross-env` on the target.
 */
class ExportProductsCrossEnvCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-inventory:export-products-cross-env
        {app_id}
        {company_id}
        {--limit=0}
        {--offset=0}
        {--output=}
        {--no-files}';

    protected $description = 'Export inventory products (names, not ids) to a JSONL file for cross-environment migration';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $withFiles = ! $this->option('no-files');

        $idsQuery = Products::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->whereHas('variants')
            ->orderBy('id');

        if ($offset > 0) {
            $idsQuery->skip($offset);
        }
        if ($limit > 0) {
            $idsQuery->take($limit);
        }

        $ids = $idsQuery->pluck('id')->all();

        if ($ids === []) {
            $this->warn('No products with variants found for the given app/company.');

            return self::SUCCESS;
        }

        $relativePath = $this->option('output')
            ?: sprintf(
                'migration/inventory_export_app_%s_company_%s_%s.jsonl',
                $app->getId(),
                $company->getId(),
                now()->format('Y_m_d_H_i_s')
            );

        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($relativePath));
        $absolutePath = $disk->path($relativePath);

        $handle = fopen($absolutePath, 'w');
        $exported = 0;
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        foreach (array_chunk($ids, 200) as $chunk) {
            $products = Products::query()
                ->whereIn('id', $chunk)
                ->with([
                    'status',
                    'productsType',
                    'categories',
                    'attributes.attribute',
                    'files',
                    'variants.status',
                    'variants.files',
                    'variants.attributes.attribute',
                    'variants.variantWarehouses.warehouse',
                    'variants.variantWarehouses.status',
                    'variants.variantChannels.channel',
                    'variants.variantChannels.warehouse',
                ])
                ->orderBy('id')
                ->get();

            foreach ($products as $product) {
                fwrite($handle, json_encode($this->mapProduct($product, $withFiles)) . PHP_EOL);
                $exported++;
                $bar->advance();
            }
        }

        $bar->finish();
        fclose($handle);
        $this->newLine(2);

        $this->info('Cross-environment export complete.');
        $this->line('App: ' . $app->name . ' (' . $app->getId() . ')');
        $this->line('Company: ' . $company->name . ' (' . $company->getId() . ')');
        $this->line('Products exported: ' . $exported);
        $this->line('File: ' . $absolutePath);

        return self::SUCCESS;
    }

    private function mapProduct(Products $product, bool $withFiles): array
    {
        return [
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->slug,
            'description' => $product->description,
            'shortDescription' => $product->short_description,
            'htmlDescription' => $product->html_description,
            'warrantyTerms' => $product->warranty_terms,
            'upc' => $product->upc,
            'isPublished' => (bool) $product->is_published,
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            'status' => $product->status?->name,
            'productType' => $this->mapProductType($product),
            'categories' => $this->mapCategories($product),
            'attributes' => $this->mapAttributes($product->attributes),
            'files' => $withFiles ? $this->mapFiles($product) : [],
            'variants' => $product->variants->map(
                fn (Variants $variant) => $this->mapVariant($variant, $withFiles)
            )->all(),
        ];
    }

    private function mapVariant(Variants $variant, bool $withFiles): array
    {
        return [
            'name' => $variant->name,
            'sku' => $variant->sku,
            'slug' => $variant->slug,
            'description' => $variant->description,
            'short_description' => $variant->short_description,
            'html_description' => $variant->html_description,
            'ean' => $variant->ean,
            'barcode' => $variant->barcode,
            'serial_number' => $variant->serial_number,
            'is_published' => (bool) $variant->is_published,
            'weight' => $variant->weight !== null ? (float) $variant->weight : null,
            'status_name' => $variant->status?->name,
            'attributes' => $this->mapAttributes($variant->attributes),
            'files' => $withFiles ? $this->mapFiles($variant) : [],
            'warehouses' => $this->mapVariantWarehouses($variant),
            'channels' => $this->mapVariantChannels($variant),
        ];
    }

    private function mapVariantWarehouses(Variants $variant): array
    {
        $warehouses = [];

        foreach ($variant->variantWarehouses as $variantWarehouse) {
            $warehouseName = $variantWarehouse->warehouse?->name;
            if ($warehouseName === null) {
                continue;
            }

            $warehouses[] = [
                'warehouse_name' => $warehouseName,
                'status_name' => $variantWarehouse->status?->name,
                'quantity' => (int) $variantWarehouse->quantity,
                'price' => (float) $variantWarehouse->price,
                'sku' => $variantWarehouse->sku,
                'position' => (int) $variantWarehouse->position,
                'serial_number' => $variantWarehouse->serial_number,
                'max_capacity' => $variantWarehouse->max_capacity !== null ? (int) $variantWarehouse->max_capacity : null,
                'is_oversellable' => (bool) $variantWarehouse->is_oversellable,
                'is_default' => (bool) $variantWarehouse->is_default,
                'is_best_seller' => (bool) $variantWarehouse->is_best_seller,
                'is_on_sale' => (bool) $variantWarehouse->is_on_sale,
                'is_on_promo' => (bool) $variantWarehouse->is_on_promo,
                'can_pre_order' => (bool) $variantWarehouse->can_pre_order,
                'is_coming_soon' => (bool) $variantWarehouse->is_coming_soon,
                'is_new' => (bool) $variantWarehouse->is_new,
                'latitude' => $variantWarehouse->latitude !== null ? (float) $variantWarehouse->latitude : null,
                'longitude' => $variantWarehouse->longitude !== null ? (float) $variantWarehouse->longitude : null,
            ];
        }

        return $warehouses;
    }

    private function mapVariantChannels(Variants $variant): array
    {
        $channels = [];

        foreach ($variant->variantChannels as $variantChannel) {
            $channelName = $variantChannel->channel?->name;
            $warehouseName = $variantChannel->warehouse?->name
                ?? $variantChannel->productVariantWarehouse?->warehouse?->name;

            if ($channelName === null || $warehouseName === null) {
                continue;
            }

            $channels[] = [
                'channel_name' => $channelName,
                'warehouse_name' => $warehouseName,
                'price' => (float) $variantChannel->price,
                'discounted_price' => (float) $variantChannel->discounted_price,
                'is_published' => (bool) $variantChannel->is_published,
                'config' => $variantChannel->config,
            ];
        }

        return $channels;
    }

    private function mapProductType(Products $product): array
    {
        $productType = $product->productsType;

        if (! $productType) {
            return [];
        }

        return [
            'name' => $productType->name,
            'description' => $productType->description,
            'weight' => $productType->weight ?? 1,
        ];
    }

    private function mapCategories(Products $product): array
    {
        return $product->categories->map(fn ($category) => [
            'name' => $category->name,
            'position' => (int) ($category->position ?? 0),
        ])->all();
    }

    /**
     * @param iterable<object> $entityAttributes pivot rows exposing ->attribute and ->value
     */
    private function mapAttributes(iterable $entityAttributes): array
    {
        $attributes = [];

        foreach ($entityAttributes as $entityAttribute) {
            $name = $entityAttribute->attribute?->name;
            if ($name === null || $entityAttribute->value === null) {
                continue;
            }

            $attributes[] = [
                'name' => $name,
                'value' => $entityAttribute->value,
            ];
        }

        return $attributes;
    }

    private function mapFiles(Products|Variants $entity): array
    {
        return $entity->files->map(fn ($file) => [
            'url' => $file->url,
            'name' => $file->name,
        ])->all();
    }
}
