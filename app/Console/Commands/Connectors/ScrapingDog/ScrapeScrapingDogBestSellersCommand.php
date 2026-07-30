<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapingDog;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductService;
use Kanvas\Connectors\ScrapingDog\Services\ProductVariantService;
use Kanvas\Inventory\Bundles\Models\Bundle;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Social\Tags\Models\TagEntity;
use Kanvas\Users\Models\Users;
use Throwable;

class ScrapeScrapingDogBestSellersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:scrapingdog-amazon-bestsellers
                            {app_id : The application ID}
                            {company_id : The company ID}
                            {userId : The user ID}
                            {region_id : The region ID}
                            {--url= : Best Sellers landing URL (defaults to the Amazon Best Sellers landing)}
                            {--categories= : Comma-separated category slugs to limit (empty = all departments)}
                            {--limit=0 : Max products per category (0 = all)}
                            {--warehouse_id= : Warehouse ID (defaults to the region default warehouse)}
                            {--tag=Homepage : Tag applied to every imported best seller}';

    protected $description = 'Scrape Amazon Best Sellers via ScrapingDog department by department, enrich each ASIN and import them';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $region = Regions::getById((int) $this->argument('region_id'));
        $user = Users::getById((int) $this->argument('userId'));

        $this->overwriteAppService($app);

        $warehouse = $this->option('warehouse_id')
            ? Warehouses::getById((int) $this->option('warehouse_id'))
            : $region->defaultWarehouse;

        if (! $warehouse) {
            $this->error('No warehouse found: pass --warehouse_id or set a default warehouse on the region.');

            return self::FAILURE;
        }

        $channel = Channels::getDefault($company);
        $tag = (string) $this->option('tag');
        $limit = (int) $this->option('limit');
        $onlySlugs = array_filter(array_map('trim', explode(',', (string) $this->option('categories'))));

        $repository = new ScrapingDogRepository($app);
        $productService = new ProductService($channel, $warehouse, $user);
        $variantService = new ProductVariantService($channel, $warehouse, $user);

        $landingUrl = $this->option('url') ?: 'https://www.amazon.com/gp/bestsellers/?ref_=nav_cs_bestsellers';

        $this->info('Scraping landing: ' . $landingUrl);
        $categories = $this->resolveCategories($repository->getBestSellerCategories($landingUrl));

        if (! empty($onlySlugs)) {
            $categories = array_values(array_filter($categories, fn ($c) => in_array($c['slug'], $onlySlugs, true)));
        }

        if (empty($categories)) {
            $this->error('No categories returned from the landing page.');

            return self::FAILURE;
        }

        // Reset the current homepage selection: the scraped best sellers become the new one.
        $this->clearHomepageTag($app, $tag);

        $imported = 0;
        $updated = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($categories as $category) {
            $items = $repository->getCategoryProducts($category['url']);
            if ($limit > 0) {
                $items = array_slice($items, 0, $limit);
            }

            $this->info(sprintf('Category "%s" (%s): %d product(s)', $category['name'], $category['slug'], count($items)));

            // Amazon lists the same product under different ASINs — keep only the first per
            // normalized name in a department so the homepage/bundle don't show duplicates.
            $seenNames = [];

            foreach ($items as $item) {
                $asin = (string) ($item['sku'] ?? $item['asin'] ?? '');
                if ($asin === '') {
                    continue;
                }

                $nameKey = Str::slug((string) ($item['name'] ?? $item['product'] ?? ''));
                if ($nameKey !== '' && isset($seenNames[$nameKey])) {
                    $duplicates++;

                    continue;
                }
                if ($nameKey !== '') {
                    $seenNames[$nameKey] = true;
                }

                $price = (float) ($item['price'] ?? 0);

                try {
                    // Already downloaded before: just refresh its price + homepage flag, no re-import.
                    if ($existing = $this->findExistingProduct($app, $company, $asin)) {
                        $this->refreshExistingProduct($existing, $price, $tag, $app, $company);
                        $updated++;

                        continue;
                    }

                    $structured = $repository->getByAsin($asin);
                    if (empty($structured)) {
                        $this->warn('  Skipping ' . $asin . ': no product data');

                        continue;
                    }

                    // mapProduct/mapVariant read the raw getByAsin shape — never pollute it with the
                    // list payload (a float `price` there breaks extractPrice's preg_match).
                    $structured['asin'] = $asin;
                    if (empty($structured['product_category'])) {
                        $structured['product_category'] = $category['name'];
                    }

                    $mapped = $productService->mapProduct($structured);
                    $mapped['variants'] = $variantService->mapVariant($structured);
                    $mapped['categories'][] = [
                        'name' => $category['name'],
                        'slug' => Str::slug($category['name']),
                        'code' => Str::slug($category['name']),
                        'position' => 0,
                    ];

                    // The best-seller list price is the reliable one — force it onto the import.
                    $this->applyPrice($mapped, $price);

                    if (empty($mapped['price'])) {
                        $this->warn('  Skipping ' . $asin . ': no price');

                        continue;
                    }

                    $product = new ProductImporterAction(
                        ProductImporter::from($mapped),
                        $company,
                        $user,
                        $region,
                        $app,
                        true
                    )->execute();

                    $product->addTag($tag, $app, company: $company);

                    try {
                        $product->searchable();
                    } catch (Throwable $indexError) {
                        $this->warn('  Imported but not indexed (' . $asin . '): ' . $indexError->getMessage());
                    }

                    $imported++;
                } catch (Throwable $e) {
                    // A duplicate sku means we already had it — treat as downloaded and reprice.
                    if (Str::contains($e->getMessage(), 'already been taken')
                        && ($existing = $this->findExistingProduct($app, $company, $asin)) !== null) {
                        try {
                            $this->refreshExistingProduct($existing, $price, $tag, $app, $company);
                            $updated++;

                            continue;
                        } catch (Throwable) {
                            // fall through to the failure path
                        }
                    }

                    $this->error('  Failed ' . $asin . ': ' . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->info('');
        $this->info('=== Import Summary ===');
        $this->info('Categories: ' . count($categories));
        $this->info('New imported: ' . $imported);
        $this->info('Existing updated: ' . $updated);
        $this->info('Duplicates skipped: ' . $duplicates);
        $this->info('Failed: ' . $failed);
        $this->info('======================');

        $this->clearCategoriesCache($app, $company, $categories);
        $this->refreshBundles($app, $company, $tag);

        return self::SUCCESS;
    }

    /**
     * The productsTags(tag:) field lives on the Category type (@cacheRedis). Product-level
     * invalidation doesn't touch it, so clear each scraped department's cache directly.
     *
     * @param array<int, array{name: string, url: string, slug: string}> $categories
     */
    private function clearCategoriesCache(Apps $app, Companies $company, array $categories): void
    {
        foreach ($categories as $category) {
            $model = Categories::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->where(
                    fn ($query) => $query->where('name', $category['name'])->orWhere('slug', Str::slug($category['name']))
                )
                ->first();

            $model?->clearLightHouseCache(withKanvasConfiguration: false);
        }
    }

    /**
     * The importer rejects a duplicate variant sku, so match on that first — the product slug
     * may differ from the asin when it was imported by another flow.
     */
    private function findExistingProduct(Apps $app, Companies $company, string $asin): ?Products
    {
        $variant = Variants::withTrashed()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('sku', $asin)
            ->first();

        if ($variant) {
            $product = Products::withTrashed()->find($variant->products_id);
            if ($product) {
                return $product;
            }
        }

        return Products::withTrashed()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('slug', Str::slug($asin))
            ->first();
    }

    /**
     * Restore + reprice an already-imported product and keep it on the homepage,
     * without going through the importer (which rejects a duplicate sku).
     */
    private function refreshExistingProduct(Products $product, float $price, string $tag, Apps $app, Companies $company): void
    {
        if ($product->is_deleted) {
            DB::connection('inventory')->table('products')
                ->where('id', $product->getId())
                ->update(['is_deleted' => 0]);
            $product->is_deleted = false;
        }

        if ($price > 0) {
            $variants = Variants::query()->where('products_id', $product->getId())->get();
            if ($variants->isNotEmpty()) {
                DB::connection('inventory')->table('products_variants_channels')
                    ->whereIn('products_variants_id', $variants->pluck('id')->all())
                    ->update(['price' => $price]);

                // DB::table bypasses model events, so invalidate the @cacheRedis manually or the
                // product/variant graph queries keep serving the stale price.
                foreach ($variants as $variant) {
                    $variant->clearLightHouseCache(withKanvasConfiguration: false);
                }
            }
        }

        $product->clearLightHouseCache(withKanvasConfiguration: false);
        $product->addTag($tag, $app, company: $company);

        try {
            $product->searchable();
        } catch (Throwable) {
            // indexing is best-effort
        }
    }

    /**
     * Force the scraped list price onto the mapped product/variants/warehouses/channels.
     */
    private function applyPrice(array &$mapped, float $price): void
    {
        if ($price <= 0) {
            return;
        }

        $mapped['price'] = $price;

        foreach ($mapped['warehouses'] ?? [] as &$warehouse) {
            $warehouse['price'] = $price;
        }
        unset($warehouse);

        foreach ($mapped['variants'] ?? [] as &$variant) {
            $variant['price'] = $price;
            foreach ($variant['warehouses'] ?? [] as &$variantWarehouse) {
                $variantWarehouse['price'] = $price;
            }
            unset($variantWarehouse);
            foreach ($variant['channels'] ?? [] as &$variantChannel) {
                $variantChannel['price'] = $price;
            }
            unset($variantChannel);
        }
        unset($variant);
    }

    /**
     * @param array<int, array<string, mixed>> $rawCategories
     * @return array<int, array{name: string, url: string, slug: string}>
     */
    private function resolveCategories(array $rawCategories): array
    {
        $categories = [];
        $seen = [];

        foreach ($rawCategories as $raw) {
            $name = trim((string) ($raw['name'] ?? ''));
            $path = (string) ($raw['url'] ?? '');
            if ($name === '' || $path === '') {
                continue;
            }

            $slug = preg_match('#/zgbs/([^/]+)#', $path, $match) ? $match[1] : Str::slug($name);
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $categories[] = [
                'name' => $name,
                'url' => str_starts_with($path, 'http') ? $path : 'https://www.amazon.com' . $path,
                'slug' => $slug,
            ];
        }

        return $categories;
    }

    private function refreshBundles(Apps $app, Companies $company, string $tag): void
    {
        $bundles = Bundle::query()->fromApp($app)->fromCompany($company)->notDeleted()->get();

        if ($bundles->isEmpty()) {
            return;
        }

        $refreshed = 0;

        foreach ($bundles as $bundle) {
            $category = Categories::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->where(
                    fn ($query) => $query->where('slug', $bundle->slug)->orWhere('name', $bundle->name)
                )
                ->first();

            if (! $category) {
                continue;
            }

            $variantIds = $this->categoryVariantIds($app, $company, $category, $tag);

            // Guard: never strip a bundle unless the replacement products actually exist.
            if (empty($variantIds)) {
                $this->warn(sprintf('Bundle "%s": no scraped products in its category, left untouched.', $bundle->name));

                continue;
            }

            $added = $this->assignVariantsToBundle($bundle->getId(), $variantIds);
            $removed = $this->softDeleteMissingBundleItems($bundle->getId(), $variantIds);
            $refreshed++;

            $this->info(sprintf(
                'Bundle "%s": %d active, %d added, %d soft-deleted.',
                $bundle->name,
                count($variantIds),
                $added,
                $removed
            ));
        }

        $this->info('');
        $this->info('=== Bundle Refresh Summary ===');
        $this->info('Bundles processed: ' . $bundles->count());
        $this->info('Bundles refreshed: ' . $refreshed);
        $this->info('==============================');
    }

    /**
     * @return array<int, int>
     */
    private function categoryVariantIds(Apps $app, Companies $company, Categories $category, string $tag): array
    {
        $productIds = Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereHas('categories', fn ($query) => $query->where('categories.id', $category->getId()))
            ->pluck('id')
            ->all();

        if (empty($productIds)) {
            return [];
        }

        if ($tag !== '') {
            $tagIds = Tag::query()
                ->fromApp($app)
                ->whereRaw('LOWER(name) = ?', [Str::lower($tag)])
                ->pluck('id')
                ->all();

            $productIds = empty($tagIds) ? [] : TagEntity::query()
                ->where('taggable_type', Products::class)
                ->where('is_deleted', 0)
                ->whereIn('tags_id', $tagIds)
                ->whereIn('entity_id', $productIds)
                ->pluck('entity_id')
                ->unique()
                ->all();
        }

        if (empty($productIds)) {
            return [];
        }

        // One variant per product so a multi-variant product doesn't flood the bundle.
        return Variants::query()
            ->whereIn('products_id', $productIds)
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get()
            ->groupBy('products_id')
            ->map(fn ($group) => $group->first()->getKey())
            ->values()
            ->all();
    }

    /**
     * bundle_items is written via the query builder on purpose: the Eloquent model boots
     * CompaniesIdTrait/AppsIdTrait which need an authed user + columns the table doesn't have.
     *
     * @param array<int, int> $variantIds
     */
    private function assignVariantsToBundle(int $bundleId, array $variantIds): int
    {
        $table = DB::connection('inventory')->table('bundle_items');
        $added = 0;

        foreach ($variantIds as $variantId) {
            $row = $table->where('bundle_id', $bundleId)->where('variant_id', $variantId)->first();

            if ($row === null) {
                DB::connection('inventory')->table('bundle_items')->insert([
                    'bundle_id' => $bundleId,
                    'variant_id' => $variantId,
                    'quantity' => 1,
                    'unit' => 'unit',
                    'is_deleted' => 0,
                ]);
                $added++;
            } elseif ((int) $row->is_deleted === 1) {
                DB::connection('inventory')->table('bundle_items')
                    ->where('id', $row->id)
                    ->update(['is_deleted' => 0]);
                $added++;
            }
        }

        return $added;
    }

    /**
     * @param array<int, int> $keepVariantIds
     */
    private function softDeleteMissingBundleItems(int $bundleId, array $keepVariantIds): int
    {
        return DB::connection('inventory')->table('bundle_items')
            ->where('bundle_id', $bundleId)
            ->where('is_deleted', 0)
            ->whereNotIn('variant_id', $keepVariantIds)
            ->update(['is_deleted' => 1]);
    }

    private function clearHomepageTag(Apps $app, string $tag): void
    {
        $homepageTagIds = Tag::query()
            ->fromApp($app)
            ->whereRaw('LOWER(name) = ?', [Str::lower($tag)])
            ->pluck('id')
            ->all();

        if (empty($homepageTagIds)) {
            return;
        }

        $cleared = TagEntity::query()
            ->where('taggable_type', Products::class)
            ->whereIn('tags_id', $homepageTagIds)
            ->delete();

        $this->info(sprintf('Cleared "%s" tag from %d product(s).', $tag, $cleared));
    }
}
