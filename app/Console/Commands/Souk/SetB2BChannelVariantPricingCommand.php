<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as DataTransferObjectChannels;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Services\B2BConfigurationService;

class SetB2BChannelVariantPricingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:set-b2b-channel-variant-pricing {app_id} {company_id} {product_type_id} {discounted_price_percentage?}';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $discountedPricePercentage = (float) ($this->argument('discounted_price_percentage') ?? 0.00);
        $this->overwriteAppService($app);

        $b2bCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $company);
        $productTypeId = (int) $this->argument('product_type_id');
        $channel = new CreateChannel(new DataTransferObjectChannels(
            app: $app,
            company: $b2bCompany,
            user: $b2bCompany->user,
            name: $company->name,
            is_published: true,
            slug: $company->uuid
        ), $b2bCompany->user)->execute();

        $variants = Variants::query()
            ->select('products_variants.*')
            ->join('products_variants_warehouses', 'products_variants_warehouses.products_variants_id', 'products_variants.id')
            ->join('products', 'products.id', 'products_variants.products_id')
            ->leftJoin('products_variants_channels', 'products_variants_channels.products_variants_id', 'products_variants.id')
            ->where('products.apps_id', $app->getId())
            ->where('products.companies_id', $b2bCompany->getId())
            ->where('products.is_published', 1)
            ->where('products.is_deleted', 0)
            ->where('products.products_types_id', $productTypeId)
            ->whereNotNull('products_variants_channels.price')
            ->get();

        /*    $defaultChannel = Channels::getDefault(
               app: $app,
               company: $b2bCompany
           ); */
        foreach ($variants as $variant) {
            try {
                $variantWarehouses = $variant->variantWarehouses->first();
                $variantChannelPrice = $variant->getPriceInfoFromDefaultChannel()->price;

                if ($discountedPricePercentage > 0) {
                    $variantChannelPrice = $variantChannelPrice - ($variantChannelPrice * $discountedPricePercentage);
                }

                if ($variantChannelPrice <= 0) {
                    $this->error('Variant ID: ' . $variant->getId() . ' has a price of 0 or less. Skipping.');

                    continue;
                }

                $variantChannel = VariantChannel::from([
                                'price' => number_format($variantChannelPrice, 2, '.', ''),
                                'discounted_price' => number_format($variantChannelPrice, 2, '.', ''),
                                'is_published' => 1,
                    ]);
                (new AddVariantToChannelAction(
                    $variantWarehouses,
                    $channel,
                    $variantChannel
                ))->execute();

                $this->info('Variant ID: ' . $variant->getId() . ' processed successfully. Price set to: ' . $variantChannelPrice . ' in channel: ' . $channel->name);
            } catch (Exception $e) {
                $this->error('Error processing variant ID: ' . $variant->getId() . '. Error: ' . $e->getMessage());
            }
        }

        $this->info('All products processed successfully.');
    }
}
