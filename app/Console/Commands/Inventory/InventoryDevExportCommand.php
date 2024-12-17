<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Support\Str;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions as KanvasRegions;

class InventoryDevExportCommand extends Command
{
    public $regionId = 0;
    public $warehouseId = 0;
    public $channelId = 0;
    public $statusId = 0;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:dev-inventory-export {app_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Migrate inventory from to development to prod';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));

        $products = Products::fromApp($app)
        ->notDeleted(0)
        //->limit(400)
        ->whereHas('variants')
        ->get();

        $productsToExport = [];

        foreach ($products as $product) {
            $productData = [
                'name' => $product->name,
                'description' => $product->description,
                'slug' => $product->slug,
                'sku' => $product->slug ?? Str::slug($product->name),
                'variants' => $this->mapVariant($product),
                'categories' => $this->mapCategories($product),
                'quantity' => 0,
                'price' => 0,
            ];
            if ($product?->productsType) {
                $productData['productType'] = $this->mapProductType($product);
            }
            $productsToExport[] = $productData;
        }

        $chunks = array_chunk($productsToExport, 10);

        $url = 'url';
        $client = new Client();
        $appKey = 'key';
        $appUuid = 'uuid';
        $companyId = $this->argument('company_id');

        $headers = [
            'X-Kanvas-App' => $appUuid,
            'X-Kanvas-Key' => $appKey
            // 'X-Kanvas-Location' => $companyInfo['branch'],
        ];

        $mutation = <<<GQL
        mutation(\$input: [ImporterProductInput!]!, \$companyId: Int!) {
            importProduct(input:\$input, companyId: \$companyId)
        }
GQL;
        foreach ($chunks as $chunk) {
            $response = $client->post(
                $url,
                [
                    'headers' => $headers,
                    'json' => [
                        'query' => $mutation,
                        'variables' => [
                            'companyId' => (int) $companyId,
                            'input' => $chunk,
                        ],
                    ],
                ]
            );

            $this->info('Inventory process for App ' . $app->name . ' ' . count($chunk) . ' - ' . $response->getBody()->getContents());
            // $this->info('Inventory process for App ' . $app->name . ' completed successful');
        }

        $this->newLine();
        // $this->info('Inventory setup for Company ' . $company->name . ' completed successful');
        $this->newLine();

        return;
    }

    public function mapVariant(Products $product)
    {
        $variantsToExport = [];

        foreach ($product->variants as $variant) {
            $variantData = [
                'name' => $variant->name,
                'description' => $variant->description,
                'short_description' => $variant->short_description,
                'html_description' => $variant->html_description,
                'sku' => $variant->sku ?? Str::slug($variant->name),
                'ean' => $variant->ean,
                'barcode' => $variant->barcode,
                'serial_number' => $variant->serial_number,
                'is_published' => $variant->is_published,
                'slug' => $variant->slug,
                'status' => $this->mapStatus($variant->status),
                'warehouses' => $this->mapWarehouses($variant),
            ];

            if ($variant?->channels) {
                $variantData['channels'] = $this->mapChannels($variant);
            }

            $variantsToExport[] = $variantData;
        }

        return $variantsToExport;
    }

    public function mapStatus(?Status $status): array
    {
        return [
            'id' => $this->statusId
        ];
    }

    public function mapWarehouses(Variants $variant): array
    {
        $warehouses = [];
        foreach ($variant->warehouses as $warehouse) {
            $warehouses[] = [
                'id' => $this->warehouseId,
                'quantity' => $warehouse->quantity,
                'status' => $warehouse->status !== null ? $this->mapStatus($warehouse->status) : null,
                'price' => $warehouse->price ?? 0,
                'sku' => $warehouse->sku,
                'position' => $warehouse->position,
                'serial_number' => $warehouse->serial_number,
                'is_oversellable' => (bool) $warehouse->is_oversellable,
                'is_default' => (bool) $warehouse->is_default,
                'is_best_seller' => (bool) $warehouse->is_best_seller,
                'is_on_sale' => (bool) $warehouse->is_on_sale,
                'is_on_promo' => (bool) $warehouse->is_on_promo,
                'can_pre_order' => (bool) $warehouse->can_pre_order,
                'is_coming_soon' => (bool) $warehouse->is_coming_soon,
                'is_new' => (bool) $warehouse->is_new,
            ];
        }

        return $warehouses;
    }

    public function mapChannels($variant)
    {
        $channelsToExport = [];

        foreach ($variant->channels as $channel) {
            $channelsToExport[] = [
                'channels_id' => $this->channelId,
                'price' => (float) $channel->price,
                'discounted_price' => (float) $channel->discounted_price,
                'is_published' => (bool) $channel->is_published,
                'warehouses_id' => $this->warehouseId
            ];
        }

        return $channelsToExport;
    }

    public function mapProductType($product): array
    {
        if ($product?->productsType) {
            return [
                'name' => $product?->productsType->name,
                'description' => $product?->productsType->description,
                'weight' => 1,
            ];
        }
        return [];
    }

    public function mapCategories($product)
    {
        $categoriesToExport = [];

        foreach ($product->categories as $category) {
            $categoriesToExport[] = [
                'name' => $category->name,
                'code' => $category->code,
                'position' => $category->position,
                'source_id' => $category->source_id,
                'companies_id' => $category->companies_id,
                'is_published' => (bool) $category->is_published,
                'weight' => $category->weight
            ];
        }

        return $categoriesToExport;
    }
}
