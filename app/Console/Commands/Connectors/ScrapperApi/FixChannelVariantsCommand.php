<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class FixChannelVariantsCommand extends Command
{
    protected $signature = 'kanvas:move-price-from-warehouse {app_id}';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $channel = Channels::where('apps_id', $app->getId())
            ->where('is_default', true)
            ->firstOrFail();
        $variants = Variants::query()
            ->select('products_variants.*')
            ->join('products_variants_warehouses', 'products_variants_warehouses.products_variants_id', 'products_variants.id')
            ->join('products', 'products.id', 'products_variants.products_id')
            ->leftJoin('products_variants_channels', 'products_variants_channels.products_variants_id', 'products_variants.id')
            ->where('products.apps_id', $app->getId())
            ->where('products.is_deleted', false)
            ->whereNull('products_variants_channels.price')
            ->whereNotNull('products_variants_warehouses.price')
            ->get();
        foreach ($variants as $variant) {
            $variantWarehouses = $variant->warehouses->first();
            $pivot = $variantWarehouses->pivot;
            $variantWarehouses = VariantsWarehouses::where('products_variants_id', $pivot->products_variants_id)
                ->where('warehouses_id', $pivot->warehouses_id)
                ->first();
            if (! $variantWarehouses) {
                $this->error('Variant warehouse not found for variant ID: ' . $variant->getId());

                continue;
            }
            $variantChannel = VariantChannel::from([
                            'price' => 0,
                            'discounted_price' => 0,
                            'is_published' => $variant->product->is_published,
                ]);
            (new AddVariantToChannelAction(
                $variantWarehouses,
                $channel,
                $variantChannel
            ))->execute();
        }

        $this->info('All products processed successfully.');
    }
}
