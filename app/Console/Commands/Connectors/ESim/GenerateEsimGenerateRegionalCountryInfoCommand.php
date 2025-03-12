<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Locations\Models\Countries;

class GenerateEsimGenerateRegionalCountryInfoCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:esim-generate-regional-country-info {app_id} {company_id} {product_type?}';
    protected $description = 'Generate eSIM recommendation';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        // Get product type from argument or default to 'regional'
        $productTypeName = $this->argument('product_type') ?? 'regional';

        $productType = ProductsTypes::fromApp($app)
            ->fromCompany($company)
            ->where('slug', $productTypeName)
            ->firstOrFail();

        $products = Products::fromApp($app)
            ->with('attributes')
            ->where('products_types_id', $productType->getId())
            ->get();

        $countryInfo = [];

        foreach ($products as $product) {
            $this->info('Processing ' . $productTypeName . ' product: ' . $product->name);

            $countries = $product->getAttributeByName('countries')?->value;

            if ($countries === null) {
                $this->error('No countries found for product: ' . $product->name);

                continue;
            }

            foreach ($countries as $country) {
                $this->info('Processing country: ' . $country);

                $country = Countries::getById((int) $country);

                $productWithAttributes = Products::fromApp($app)
                    ->fromCompany($company)
                    ->where('slug', $product->slug)
                    ->with('attributes')
                    ->first();

                $firstVariant = $productWithAttributes->variants->first();

                if ($firstVariant === null) {
                    $this->error('No variants found for product: ' . $product->name);

                    continue;
                }

                $network = $firstVariant->getAttributeBySlug('variant-network')?->value;
                $speed = $firstVariant->getAttributeBySlug('variant-speed')?->value;

                $countryInfo[] = [
                    'country' => $country->name,
                    'flag' => 'https://flagcdn.com/w320/' . $country->code . '.png',
                    'carriers' => [
                        [
                            'name' => $network,
                            'networks' => [
                                $speed,
                            ],
                        ],
                    ],
                ];
            }

            $product->addAttribute('Countries Details', $countryInfo);
            $countryInfo = [];
        }
    }
}
