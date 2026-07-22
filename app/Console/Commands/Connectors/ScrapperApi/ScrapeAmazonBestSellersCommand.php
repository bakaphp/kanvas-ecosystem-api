<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\AmazonBestSellersParser;
use Kanvas\Connectors\ScrapperApi\Services\ProductService;
use Kanvas\Connectors\ScrapperApi\Services\ProductVariantService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Social\Tags\Models\TagEntity;
use Kanvas\Users\Models\Users;
use Throwable;

class ScrapeAmazonBestSellersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:scrapper-amazon-bestsellers
                            {app_id : The application ID}
                            {company_id : The company ID}
                            {userId : The user ID}
                            {region_id : The region ID}
                            {--url= : Best Sellers landing URL (defaults to the Amazon Best Sellers landing)}
                            {--categories= : Comma-separated category slugs to limit (empty = all departments)}
                            {--limit=0 : Max products per category (0 = all)}
                            {--warehouse_id= : Warehouse ID (defaults to the region default warehouse)}
                            {--tag=Homepage : Tag applied to every imported best seller}';

    protected $description = 'Scrape Amazon Best Sellers department by department, enrich each ASIN via the structured product endpoint and import them';

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

        $repository = new ScrapperRepository($app);
        $productService = new ProductService($channel, $warehouse, $user);
        $variantService = new ProductVariantService($channel, $warehouse, $user);

        $landingUrl = $this->option('url') ?: 'https://www.amazon.com/gp/bestsellers/?ref_=nav_cs_bestsellers';

        $this->info('Scraping landing: ' . $landingUrl);
        $categories = AmazonBestSellersParser::parseCategoryLinks($repository->getRenderedPage($landingUrl));

        if (! empty($onlySlugs)) {
            $categories = array_values(array_filter($categories, fn ($c) => in_array($c['slug'], $onlySlugs, true)));
        }

        if (empty($categories)) {
            $this->error('No category links parsed from the landing page.');

            return self::FAILURE;
        }

        // Reset the current homepage selection: the scraped best sellers become the new one.
        $this->clearHomepageTag($app, $tag);

        $success = 0;
        $failed = 0;

        foreach ($categories as $category) {
            $products = AmazonBestSellersParser::parseProducts($repository->getRenderedPage($category['url']));
            if ($limit > 0) {
                $products = array_slice($products, 0, $limit);
            }

            $this->info(sprintf('Category "%s" (%s): %d product(s)', $category['name'], $category['slug'], count($products)));

            foreach ($products as $item) {
                try {
                    // Un-delete a previously removed product so the importer reuses it instead of leaving it hidden.
                    Products::withTrashed()->where('slug', Str::slug($item['asin']))->update(['is_deleted' => 0]);

                    $structured = $repository->getByAsin($item['asin']);
                    $merged = array_merge($structured, $item);

                    // getByAsin's breadcrumb is sometimes empty — fall back to the department
                    // we scraped it from, which always exists in the best-seller list.
                    if (empty($merged['product_category'])) {
                        $merged['product_category'] = $category['name'];
                    }

                    if (empty($merged['price']) && empty($merged['pricing'])) {
                        $this->warn('  Skipping ' . $item['asin'] . ': no price');

                        continue;
                    }

                    $mapped = $productService->mapProduct($merged);
                    $mapped['variants'] = $variantService->mapVariant($merged);
                    // Always land it under the best-seller department (getByAsin's breadcrumb can be empty).
                    $mapped['categories'][] = [
                        'name' => $category['name'],
                        'slug' => Str::slug($category['name']),
                        'code' => Str::slug($category['name']),
                        'position' => 0,
                    ];

                    $product = new ProductImporterAction(
                        ProductImporter::from($mapped),
                        $company,
                        $user,
                        $region,
                        $app,
                        true
                    )->execute();

                    $product->addTag($tag, $app, company: $company);

                    // The product is already imported with its category; a search-index hiccup
                    // (e.g. Typesense indexing it before categories attach) must not fail it.
                    try {
                        $product->searchable();
                    } catch (Throwable $indexError) {
                        $this->warn('  Imported but not indexed (' . $item['asin'] . '): ' . $indexError->getMessage());
                    }

                    $success++;
                } catch (Throwable $e) {
                    $this->error('  Failed ' . $item['asin'] . ': ' . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->info('');
        $this->info('=== Import Summary ===');
        $this->info('Categories: ' . count($categories));
        $this->info('Imported: ' . $success);
        $this->info('Failed: ' . $failed);
        $this->info('======================');

        return self::SUCCESS;
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
