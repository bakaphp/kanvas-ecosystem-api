<?php

declare(strict_types=1);

namespace Kanvas\Souk\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;

class ConfigureB2BCompanyPricingAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $buyerCompany,
        protected readonly Companies $mainAppCompany,
        protected readonly float $discountedPricePercentage,
        protected readonly array $productTypes = [],
    ) {
    }

    public function execute(): array
    {
        $channel = new CreateChannel(
            new Channels(
                app: $this->app,
                company: $this->mainAppCompany,
                user: $this->mainAppCompany->user,
                name: $this->buyerCompany->name,
                description: $this->buyerCompany->name . ' channel',
                slug: $this->buyerCompany->uuid
            ),
            $this->mainAppCompany->user
        )->execute();

        $variants = Variants::query()
            ->select('products_variants.*')
            ->join('products', 'products.id', 'products_variants.products_id')
            ->whereHas('variantWarehouses')
            ->whereHas('channels', function (Builder $query) {
                $query->whereNotNull('products_variants_channels.price');
            })
            ->where('products.apps_id', $this->app->getId())
            ->where('products.companies_id', $this->mainAppCompany->getId())
            ->where('products.is_published', 1)
            ->where('products.is_deleted', 0)
            ->when(! empty($this->productTypes), function (Builder $query): Builder {
                return $query->whereIn('products.products_types_id', $this->productTypes);
            })
            ->get();

        $results = [
            'message' => 'Processing variants for company: ' . $this->buyerCompany->name,
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->name,
            'summary' => [
                'total_variants' => 0,
                'processed_successfully' => 0,
                'skipped_zero_price' => 0,
                'errors' => 0,
            ],
            'processing_details' => [
                'discount_percentage' => $this->discountedPricePercentage,
                'processed_at' => now()->toISOString(),
                'product_types_filter' => $this->productTypes,
            ],
        ];

        foreach ($variants as $variant) {
            $results['summary']['total_variants']++;

            try {
                $variantWarehouses = $variant->variantWarehouses->first();
                $variantChannelPrice = (float) $variant->getPriceInfoFromDefaultChannel()->price;

                if ($this->discountedPricePercentage > 0) {
                    $variantChannelPrice -= $variantChannelPrice * $this->discountedPricePercentage;
                }

                if ($variantChannelPrice <= 0) {
                    $results['summary']['skipped_zero_price']++;

                    continue;
                }

                $variantChannel = VariantChannel::from([
                    'price' => number_format($variantChannelPrice, 2, '.', ''),
                    'discounted_price' => number_format($variantChannelPrice, 2, '.', ''),
                    'is_published' => 1,
                ]);

                new AddVariantToChannelAction(
                    $variantWarehouses,
                    $channel,
                    $variantChannel
                )->execute();

                $results['summary']['processed_successfully']++;
            } catch (Exception $e) {
                $results['summary']['errors']++;
                report($e);
            }
        }

        return $results;
    }
}
