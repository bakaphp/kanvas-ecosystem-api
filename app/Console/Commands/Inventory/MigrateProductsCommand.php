<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Migrate (copy) inventory products between companies in the SAME environment — same app, or across
 * different apps. Reads straight from the source company/app and writes to the destination through the
 * existing ProductImporterAction pipeline, without any file round-trip: it serializes each source product
 * to a name-based record, resolves those names against the destination (creating any missing
 * warehouse/channel/status on first sight), and imports.
 *
 * Copy is the default and is re-runnable: CreateProductAction upserts by slug + apps_id + companies_id, so
 * a product already present in the destination is updated, never duplicated. Pass --move to soft-delete
 * each source product once it has migrated successfully (a real move). Use --dry-run to preview first.
 *
 * Every run writes a revert manifest to the local disk (unless --no-manifest). Passing --revert=<manifest>
 * undoes that run: it restores any soft-deleted source products and soft-deletes only the destination
 * products the run newly created (pre-existing destination products that were merely updated are left
 * intact, since their prior field values aren't recoverable from the manifest).
 */
class MigrateProductsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * Relations a source product must have eager-loaded so serialization sees everything without N+1.
     *
     * @var array<int, string>
     */
    private const RELATIONS = [
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
    ];

    protected $signature = 'kanvas-inventory:migrate-products
        {--from-app= : Source app id}
        {--from-company= : Source company id}
        {--to-app= : Destination app id (defaults to --from-app)}
        {--to-company= : Destination company id}
        {--user-id= : Destination user id; must belong to the destination company and be an admin to preserve is_published}
        {--to-region= : Destination region id; defaults to the destination company default region}
        {--product-ids= : Comma-separated source product ids to migrate (default: every product with variants)}
        {--limit=0 : Max number of source products to migrate}
        {--offset=0 : Skip the first N source products}
        {--move : Soft-delete each source product after it migrates successfully (default: copy, source untouched)}
        {--skip-files : Do not migrate product/variant images}
        {--run-workflow : Fire the product CREATED workflow on each migrated product (off by default)}
        {--dry-run : Report what would be migrated without writing anything}
        {--no-manifest : Do not write a revert manifest for this run}
        {--revert= : Path to a manifest from a previous run to undo (restores source, removes products this run created)}
        {--force : Skip the interactive confirmation (for automation)}';

    protected $description = 'Migrate inventory products between companies (same or different app) within one environment';

    /** @var array<string, int> destination warehouse name → id, memoized firstOrCreate */
    private array $warehouseMap = [];

    /** @var array<string, int> destination channel name → id, memoized firstOrCreate */
    private array $channelMap = [];

    /** @var array<string, int> destination status name → id, memoized firstOrCreate */
    private array $statusMap = [];

    public function handle(): int
    {
        if ($this->option('revert')) {
            return $this->revert((string) $this->option('revert'));
        }

        $fromApp = $this->resolveApp('from-app');
        $fromCompany = $this->resolveCompany('from-company');
        if ($fromApp === null || $fromCompany === null) {
            return self::FAILURE;
        }

        $toApp = $this->option('to-app') ? $this->resolveApp('to-app') : $fromApp;
        $toCompany = $this->resolveCompany('to-company');
        if ($toApp === null || $toCompany === null) {
            return self::FAILURE;
        }

        $isMove = (bool) $this->option('move');
        $sameTenant = $fromApp->getId() === $toApp->getId() && $fromCompany->getId() === $toCompany->getId();
        if ($isMove && $sameTenant) {
            $this->error('--move onto the same app+company would soft-delete the products you just wrote. Aborting.');

            return self::FAILURE;
        }

        // All writes (products, warehouses, channels, statuses, Bouncer role lookups) resolve against the
        // DESTINATION app — bind it before touching any scoped model.
        $this->overwriteAppService($toApp);
        Bouncer::scope()->to(RolesEnums::getScope($toApp));

        $userId = (int) $this->option('user-id');
        if ($userId === 0) {
            $this->error('--user-id is required (a user that belongs to destination company ' . $toCompany->getId() . ').');

            return self::FAILURE;
        }
        $user = Users::getById($userId);
        auth()->setUser($user);

        $region = $this->option('to-region')
            ? RegionRepository::getById((int) $this->option('to-region'), $toCompany)
            : Regions::getDefault($toCompany, $toApp);

        if ($region === null) {
            $this->error('No region found for the destination company. Create a default region first or pass --to-region.');

            return self::FAILURE;
        }

        $ids = $this->sourceProductIds($fromApp, $fromCompany);
        if ($ids === []) {
            $this->warn('No matching products with variants found in the source app/company.');

            return self::SUCCESS;
        }

        $skipFiles = (bool) $this->option('skip-files');
        $runWorkflow = (bool) $this->option('run-workflow');
        $dryRun = (bool) $this->option('dry-run');

        $this->printPlan($fromApp, $fromCompany, $toApp, $toCompany, $region, count($ids), $isMove, $skipFiles, $runWorkflow, $dryRun);

        if ($dryRun) {
            return $this->reportDryRun($ids);
        }

        if (! $this->option('force') && ! $this->confirm('Proceed with the migration above?', false)) {
            $this->warn('Aborted. Nothing was written.');

            return self::SUCCESS;
        }

        return $this->migrate($ids, $fromApp, $fromCompany, $toApp, $toCompany, $user, $region, $isMove, $skipFiles, $runWorkflow);
    }

    private function migrate(
        array $ids,
        Apps $fromApp,
        Companies $fromCompany,
        Apps $toApp,
        Companies $toCompany,
        Users $user,
        Regions $region,
        bool $isMove,
        bool $skipFiles,
        bool $runWorkflow
    ): int {
        $migrated = 0;
        $deleted = 0;
        $failed = 0;
        $failures = [];
        $manifest = [];

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        foreach (array_chunk($ids, 200) as $chunk) {
            $products = Products::query()
                ->whereIn('id', $chunk)
                ->where('apps_id', $fromApp->getId())
                ->where('companies_id', $fromCompany->getId())
                ->with(self::RELATIONS)
                ->orderBy('id')
                ->get();

            foreach ($products as $product) {
                try {
                    $payload = $this->remapRecord(
                        $this->serializeProduct($product, ! $skipFiles),
                        $toCompany,
                        $toApp,
                        $user,
                        $region,
                        $skipFiles
                    );

                    $destProduct = new ProductImporterAction(
                        ProductImporter::from($payload),
                        $toCompany,
                        $user,
                        $region,
                        $toApp,
                        $runWorkflow,
                    )->execute();

                    $migrated++;

                    $manifest[] = [
                        'source_id' => $product->getId(),
                        'source_slug' => $product->slug,
                        'dest_id' => $destProduct->getId(),
                        'dest_created' => $destProduct->wasRecentlyCreated,
                    ];

                    // Only remove the source once its copy is committed in the destination. A product
                    // that failed to migrate is left intact so nothing is lost.
                    if ($isMove && $product->softDelete()) {
                        $deleted++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $failures[] = [
                        'id' => $product->getId(),
                        'slug' => $product->slug,
                        'error' => $e->getMessage(),
                    ];
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Migration complete.');
        $this->line(sprintf(
            'Resolved %d warehouse(s), %d channel(s), %d status(es) in destination.',
            count($this->warehouseMap),
            count($this->channelMap),
            count($this->statusMap)
        ));
        $this->line('Migrated (created/updated): ' . $migrated);
        if ($isMove) {
            $this->line('Source products soft-deleted: ' . $deleted);
        }
        $this->line('Failed: ' . $failed);

        if ($failures !== []) {
            $this->newLine();
            $this->warn('The following source products failed and were left untouched:');
            $this->table(['Source ID', 'Slug', 'Error'], array_map(
                fn (array $f) => [$f['id'], $f['slug'], $f['error']],
                $failures
            ));
        }

        if ($manifest !== [] && ! $this->option('no-manifest')) {
            $manifestPath = $this->writeManifest([
                'created_at' => now()->toIso8601String(),
                'mode' => $isMove ? 'move' : 'copy',
                'from_app_id' => $fromApp->getId(),
                'from_company_id' => $fromCompany->getId(),
                'to_app_id' => $toApp->getId(),
                'to_company_id' => $toCompany->getId(),
            ], $manifest);

            $this->newLine();
            $this->line('Revert manifest: ' . $manifestPath);
            $this->line('To undo this migration run:');
            $this->line('  php artisan kanvas-inventory:migrate-products --revert="' . $manifestPath . '" --force');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reportDryRun(array $ids): int
    {
        $rows = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $products = Products::query()
                ->whereIn('id', $chunk)
                ->withCount('variants')
                ->orderBy('id')
                ->get(['id', 'name', 'slug']);

            foreach ($products as $product) {
                $rows[] = [$product->getId(), $product->name, $product->slug, $product->variants_count];
            }
        }

        $this->table(['Source ID', 'Name', 'Slug', 'Variants'], $rows);
        $this->info('Dry run: ' . count($rows) . ' product(s) would be migrated. Nothing was written.');

        return self::SUCCESS;
    }

    private function printPlan(
        Apps $fromApp,
        Companies $fromCompany,
        Apps $toApp,
        Companies $toCompany,
        Regions $region,
        int $count,
        bool $isMove,
        bool $skipFiles,
        bool $runWorkflow,
        bool $dryRun
    ): void {
        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Inventory product migration');
        $this->table(['Setting', 'Value'], [
            ['Mode', $isMove ? 'MOVE (soft-delete source after success)' : 'COPY (source untouched)'],
            ['Source', sprintf('%s (%d) / company %s (%d)', $fromApp->name, $fromApp->getId(), $fromCompany->name, $fromCompany->getId())],
            ['Destination', sprintf('%s (%d) / company %s (%d)', $toApp->name, $toApp->getId(), $toCompany->name, $toCompany->getId())],
            ['Destination region', sprintf('%s (%d)', $region->name, $region->getId())],
            ['Products', (string) $count],
            ['Files', $skipFiles ? 'skipped' : 'migrated'],
            ['Product CREATED workflow', $runWorkflow ? 'fired' : 'off'],
        ]);
    }

    private function sourceProductIds(Apps $fromApp, Companies $fromCompany): array
    {
        $query = Products::query()
            ->where('apps_id', $fromApp->getId())
            ->where('companies_id', $fromCompany->getId())
            ->where('is_deleted', 0)
            ->whereHas('variants')
            ->orderBy('id');

        if ($productIds = $this->parseProductIds()) {
            $query->whereIn('id', $productIds);
        }

        $offset = (int) $this->option('offset');
        if ($offset > 0) {
            $query->skip($offset);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->take($limit);
        }

        return $query->pluck('id')->all();
    }

    /**
     * @return array<int, int>
     */
    private function parseProductIds(): array
    {
        $raw = (string) $this->option('product-ids');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $id) => (int) trim($id),
            explode(',', $raw)
        )));
    }

    private function resolveApp(string $option): ?Apps
    {
        $id = (int) $this->option($option);
        if ($id === 0) {
            $this->error('--' . $option . ' is required.');

            return null;
        }

        return Apps::getById($id);
    }

    private function resolveCompany(string $option): ?Companies
    {
        $id = (int) $this->option($option);
        if ($id === 0) {
            $this->error('--' . $option . ' is required.');

            return null;
        }

        return Companies::getById($id);
    }

    private function revert(string $manifestOption): int
    {
        $path = $this->resolveManifestPath($manifestOption);
        if ($path === null) {
            $this->error('--revert manifest not found: ' . $manifestOption);

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest) || empty($manifest['products'])) {
            $this->error('Manifest is empty or invalid: ' . $path);

            return self::FAILURE;
        }

        $fromApp = Apps::getById((int) $manifest['from_app_id']);
        $toApp = Apps::getById((int) $manifest['to_app_id']);
        $entries = $manifest['products'];
        $destCreated = count(array_filter($entries, fn (array $e) => ! empty($e['dest_created'])));

        $this->info('Revert migration');
        $this->table(['Setting', 'Value'], [
            ['Manifest', $path],
            ['Original mode', (string) ($manifest['mode'] ?? 'unknown')],
            ['Ran at', (string) ($manifest['created_at'] ?? 'unknown')],
            ['Source', sprintf('app %d / company %d', $fromApp->getId(), (int) $manifest['from_company_id'])],
            ['Destination', sprintf('app %d / company %d', $toApp->getId(), (int) $manifest['to_company_id'])],
            ['Products in manifest', (string) count($entries)],
            ['Source products to restore (if soft-deleted)', (string) count($entries)],
            ['Destination products to soft-delete (created by this run)', (string) $destCreated],
        ]);

        if (! $this->option('force') && ! $this->confirm('Revert the migration above?', false)) {
            $this->warn('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        $restored = 0;
        $this->overwriteAppService($fromApp);
        foreach ($entries as $entry) {
            $source = Products::query()
                ->where('id', (int) $entry['source_id'])
                ->where('apps_id', $fromApp->getId())
                ->first();

            if ($source !== null && $source->is_deleted) {
                $source->is_deleted = 0;
                $source->saveOrFail();
                if ($source->shouldBeSearchable()) {
                    $source->searchable();
                }
                $restored++;
            }
        }

        $removed = 0;
        $this->overwriteAppService($toApp);
        Bouncer::scope()->to(RolesEnums::getScope($toApp));
        foreach ($entries as $entry) {
            if (empty($entry['dest_created'])) {
                continue;
            }
            $dest = Products::query()
                ->where('id', (int) $entry['dest_id'])
                ->where('apps_id', $toApp->getId())
                ->first();

            if ($dest !== null && ! $dest->is_deleted && $dest->softDelete()) {
                $removed++;
            }
        }

        $this->newLine();
        $this->info('Revert complete.');
        $this->line('Source products restored: ' . $restored);
        $this->line('Destination products soft-deleted: ' . $removed);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<int, array<string, mixed>> $entries
     */
    private function writeManifest(array $header, array $entries): string
    {
        $relativePath = sprintf(
            'migration/migrate_manifest_app_%d_company_%d_to_app_%d_company_%d_%s.json',
            $header['from_app_id'],
            $header['from_company_id'],
            $header['to_app_id'],
            $header['to_company_id'],
            now()->format('Y_m_d_H_i_s')
        );

        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($relativePath));
        $absolutePath = $disk->path($relativePath);

        file_put_contents($absolutePath, json_encode([...$header, 'products' => $entries], JSON_PRETTY_PRINT));

        return $absolutePath;
    }

    private function resolveManifestPath(string $file): ?string
    {
        if ($file === '') {
            return null;
        }
        if (is_file($file)) {
            return $file;
        }
        $diskPath = Storage::disk('local')->path($file);

        return is_file($diskPath) ? $diskPath : null;
    }

    /**
     * Serialize a source product into a portable, name-based record. Every foreign key
     * (warehouse / channel / status / category / attribute / product type) is emitted by NAME so it can be
     * resolved against the destination's own ids in remapRecord(). No source id ever crosses over.
     */
    private function serializeProduct(Products $product, bool $withFiles): array
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

    /**
     * Rewrite a serialized record into the canonical ProductImporter payload for the destination,
     * translating every warehouse/channel/status name into destination ids. The product-level status name
     * is left as-is — ProductImporterAction resolves it itself.
     */
    private function remapRecord(
        array $record,
        Companies $company,
        Apps $app,
        Users $user,
        Regions $region,
        bool $skipFiles
    ): array {
        $productWarehouseNames = [];

        foreach ($record['variants'] ?? [] as $index => $variant) {
            if (! empty($variant['status_name'])) {
                $variant['status'] = ['id' => $this->resolveStatus((string) $variant['status_name'], $company, $app, $user)];
            }
            unset($variant['status_name']);

            $warehouses = [];
            foreach ($variant['warehouses'] ?? [] as $warehouse) {
                $name = $warehouse['warehouse_name'] ?? null;
                if ($name === null) {
                    continue;
                }
                $productWarehouseNames[$name] = true;
                $warehouse['id'] = $this->resolveWarehouse((string) $name, $company, $app, $user, $region);

                // Intentionally do NOT pass a per-warehouse 'status'. WarehouseService::addToWarehouses()
                // has a bug where setting it does `$status = ...->getId()` then `$status->getId()` again
                // ("Call to a member function getId() on int"). Omitting it falls back to the company
                // default status, which is what we want for a seed anyway.
                unset($warehouse['warehouse_name'], $warehouse['status_name']);
                $warehouses[] = $warehouse;
            }
            $variant['warehouses'] = $warehouses;

            $channels = [];
            foreach ($variant['channels'] ?? [] as $channel) {
                $channelName = $channel['channel_name'] ?? null;
                $warehouseName = $channel['warehouse_name'] ?? null;
                if ($channelName === null || $warehouseName === null) {
                    continue;
                }
                $channel['channels_id'] = $this->resolveChannel((string) $channelName, $company, $app, $user);
                $channel['warehouses_id'] = $this->resolveWarehouse((string) $warehouseName, $company, $app, $user, $region);
                unset($channel['channel_name'], $channel['warehouse_name']);
                $channels[] = $channel;
            }
            $variant['channels'] = $channels;

            if ($skipFiles) {
                $variant['files'] = [];
            }

            $record['variants'][$index] = $variant;
        }

        $record['warehouses'] = $productWarehouseNames === []
            ? [['warehouse' => 'default', 'channel' => 'default']]
            : array_map(
                fn (string $name) => ['warehouse' => $name, 'channel' => 'default'],
                array_keys($productWarehouseNames)
            );

        if (empty($record['productType'])) {
            unset($record['productType']);
        }

        if ($skipFiles) {
            $record['files'] = [];
        }

        return $record;
    }

    private function resolveWarehouse(string $name, Companies $company, Apps $app, Users $user, Regions $region): int
    {
        return $this->warehouseMap[$name] ??= new CreateWarehouseAction(
            new WarehousesDto(
                company: $company,
                app: $app,
                user: $user,
                region: $region,
                name: $name,
            ),
            $user,
        )->execute()->getId();
    }

    private function resolveChannel(string $name, Companies $company, Apps $app, Users $user): int
    {
        return $this->channelMap[$name] ??= new CreateChannel(
            new ChannelsDto(
                app: $app,
                company: $company,
                user: $user,
                name: $name,
            ),
            $user,
        )->execute()->getId();
    }

    private function resolveStatus(string $name, Companies $company, Apps $app, Users $user): int
    {
        return $this->statusMap[$name] ??= new CreateStatusAction(
            new StatusDto(
                app: $app,
                company: $company,
                user: $user,
                name: $name,
            ),
            $user,
        )->execute()->getId();
    }
}
