<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class InventoryShopifyCheckCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:shopify-check {app_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Check inventory for the company';

    public function handle(): void
    {
        //@todo make this run for multiple apps by looking for them at apps settings flag
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));

        ['variants' => $variants, 'products' => $products] = $this->findMissingShopifyVariants($app, $company);
        $missingProducts = $this->findMissingShopifyProducts($app, $company);

        $this->info('Missing shopify id in variants'.$company->name.': '.count($variants));
        $this->info('Missing shopify variants grouped by products'.$company->name.': '.count($products));
        $this->info('Missing shopify id in products'.$company->name.': '.count($missingProducts));
        Storage::disk('local')->put('missing_variants_shopify.json', json_encode([
            'missing_barcodes'                    => $variants,
            'missing_variants_grouped_by_product' => $products,
            'missing_products'                    => $missingProducts,

        ]));
    }

    protected function findMissingShopifyVariants(AppInterface $app, CompanyInterface $company): array
    {
        $productQuery = Variants::query()
        ->whereDoesntHave('customFields', fn ($query) => $query->whereRaw('name like ?', '%'.CustomFieldEnum::SHOPIFY_VARIANT_ID->value.'%'))
        ->where([
            'companies_id' => $company->getId(),
            'apps_id'      => $app->getId(),
        ])
        ->select('id', 'barcode', 'products_id');

        return [
            'variants' => $productQuery->get(),
            'products' => $productQuery->pluck('products_id')->unique()->toArray(),
        ];
    }

    protected function findMissingShopifyProducts(AppInterface $app, CompanyInterface $company): array
    {
        $foundProducts = Products::query()
        ->whereDoesntHave('customFields', fn ($query) => $query->whereRaw('name like ?', '%'.CustomFieldEnum::SHOPIFY_PRODUCT_ID->value.'%'))
        ->where([
            'companies_id' => $company->getId(),
            'apps_id'      => $app->getId(),
        ])
        ->pluck('id')
        ->toArray();

        return $foundProducts;
    }
}
