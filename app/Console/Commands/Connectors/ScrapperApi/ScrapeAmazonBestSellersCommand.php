<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\AmazonBestSellersParser;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
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
                            {--url=https://www.amazon.com/gp/bestsellers/?ref_=nav_cs_bestsellers : Amazon Best Sellers URL to scrape}
                            {--warehouse_id= : Warehouse ID (defaults to the region default warehouse)}
                            {--tag=Homepage : Tag applied to every imported product}
                            {--limit=0 : Max products per category (0 = all)}';

    protected $description = 'Scrape the Amazon Best Sellers page via ScraperAPI, parse it per category and import the products';

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

        $this->info('Scraping ' . $this->option('url'));
        $markdown = new ScrapperRepository($app)->getRenderedPage($this->option('url'));
        $categories = AmazonBestSellersParser::parse($markdown);

        if (empty($categories)) {
            $this->error('No best-seller products parsed from the page.');

            return self::FAILURE;
        }

        $success = 0;
        $failed = 0;

        foreach ($categories as $category) {
            $products = $limit > 0 ? array_slice($category['products'], 0, $limit) : $category['products'];
            $this->info(sprintf('Category "%s": %d product(s)', $category['category'], count($products)));

            foreach ($products as $item) {
                $mapped = $this->mapProduct($item, $category['category'], $warehouse, $channel, $tag);

                try {
                    $product = new ProductImporterAction(
                        ProductImporter::from($mapped),
                        $company,
                        $user,
                        $region,
                        $app,
                        true
                    )->execute();

                    $product->searchable();
                    $success++;
                } catch (Throwable $e) {
                    $this->error('Failed "' . $item['name'] . '" (' . $item['asin'] . '): ' . $e->getMessage());
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

    private function mapProduct(array $item, string $categoryName, Warehouses $warehouse, ?Channels $channel, string $tag): array
    {
        $files = [
            [
                'url' => $item['image'],
                'name' => 'main_image',
                'field_name' => 'product_image',
            ],
        ];

        $warehouseRow = [
            'id' => $warehouse->id,
            'price' => $item['price'],
            'warehouse' => $warehouse->name,
            'quantity' => 1,
            'sku' => $item['asin'],
            'is_new' => true,
            'channel' => $channel?->name ?? 'Default',
        ];

        $variant = [
            'name' => $item['name'],
            'slug' => Str::slug($item['asin']),
            'sku' => $item['asin'],
            'price' => $item['price'],
            'quantity' => 1,
            'files' => $files,
            'warehouses' => [
                [
                    'id' => $warehouse->id,
                    'price' => $item['price'],
                    'quantity' => 1,
                    'sku' => $item['asin'],
                    'is_new' => true,
                ],
            ],
            'channels' => [
                [
                    'price' => $item['price'],
                    'discounted_price' => null,
                    'is_published' => true,
                    'warehouses_id' => $warehouse->id,
                    'channels_id' => $channel?->id ?? 0,
                ],
            ],
        ];

        return [
            'name' => $item['name'],
            'description' => $item['name'],
            'slug' => Str::slug($item['asin']),
            'sku' => $item['asin'],
            'source' => 'amazon',
            'source_id' => $item['asin'],
            'isPublished' => true,
            'position' => $item['rank'],
            'files' => $files,
            'categories' => [
                [
                    'name' => $categoryName,
                    'slug' => Str::slug($categoryName),
                    'code' => Str::slug($categoryName),
                    'position' => 0,
                ],
            ],
            'tags' => [
                ['name' => $tag],
            ],
            'warehouses' => [$warehouseRow],
            'attributes' => [
                [
                    'name' => ConfigEnum::SCRAPPER_RATING->value,
                    'value' => $item['rating'],
                ],
            ],
            'custom_fields' => [
                [
                    'name' => ConfigEnum::AMAZON_ID->value,
                    'data' => $item['asin'],
                ],
                [
                    'name' => ConfigEnum::AMAZON_PRICE->value,
                    'data' => $item['price'],
                ],
                [
                    'name' => ConfigEnum::SCRAPPER_RATING->value,
                    'data' => $item['rating'],
                ],
            ],
            'variants' => [$variant],
        ];
    }
}
