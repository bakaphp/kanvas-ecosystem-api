<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class B2BCompanyPriceConfigurationActivity extends KanvasActivity
{
    public function execute(Companies $buyerCompany, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $mainAppCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $buyerCompany);
        $productTypes = $params['product_types'] ?? [];
        $discountedPricePercentage = $buyerCompany->get('b2b_discounted_price_percentage') ?? 0.00;

        if (empty($discountedPricePercentage) || (float) $discountedPricePercentage <= 0) {
            return [
                'status' => 'error',
                'message' => 'Discounted price percentage is not set for the buyer company.',
            ];
        }

        return $this->executeIntegration(
            entity: $buyerCompany,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($buyerCompany, $app, $integrationCompany, $additionalParams) use ($mainAppCompany, $productTypes, $discountedPricePercentage) {
                /**
                 * @todo move this logic to a action
                 */
                $channel = new CreateChannel(
                    new Channels(
                        app: $app,
                        company: $mainAppCompany,
                        user: $mainAppCompany->user,
                        name: $buyerCompany->name,
                        description: $buyerCompany->name . ' channel',
                        slug: (string) $buyerCompany->uuid
                    ),
                    $mainAppCompany->user
                )->execute();

                $variants = Variants::query()
                    ->select('products_variants.*')
                    ->join('products_variants_warehouses', 'products_variants_warehouses.products_variants_id', 'products_variants.id')
                    ->join('products', 'products.id', 'products_variants.products_id')
                    ->leftJoin('products_variants_channels', 'products_variants_channels.products_variants_id', 'products_variants.id')
                    ->where('products.apps_id', $app->getId())
                    ->where('products.companies_id', $mainAppCompany->getId())
                    ->where('products.is_published', 1)
                    ->where('products.is_deleted', 0)
                    ->whereNotNull('products_variants_channels.price')
                    ->when(! empty($productTypes), function ($query) use ($productTypes) {
                        return $query->whereIn('products.products_types_id', $productTypes);
                    })
                    ->get();

                $results = [
                    'message' => 'Processing variants for company: ' . $buyerCompany->name,
                    'channel_id' => $channel->getId(),
                    'channel_name' => $channel->name,
                    'summary' => [
                        'total_variants' => 0,
                        'processed_successfully' => 0,
                        'skipped_zero_price' => 0,
                        'errors' => 0,
                    ],
                    'processing_details' => [
                        'discount_percentage' => $discountedPricePercentage,
                        'processed_at' => now()->toISOString(),
                        'product_types_filter' => $productTypes,
                    ],
                ];

                foreach ($variants as $variant) {
                    $results['summary']['total_variants']++;

                    try {
                        $variantWarehouses = $variant->variantWarehouses->first();
                        $variantChannelPrice = $variant->getPriceInfoFromDefaultChannel()->price;

                        if ($discountedPricePercentage > 0) {
                            $variantChannelPrice = $variantChannelPrice - ($variantChannelPrice * $discountedPricePercentage);
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

                        (new AddVariantToChannelAction(
                            $variantWarehouses,
                            $channel,
                            $variantChannel
                        ))->execute();

                        $results['summary']['processed_successfully']++;
                    } catch (Exception $e) {
                        $results['summary']['errors']++;
                        report($e);
                    }
                }

                return $results;
            },
            company: $mainAppCompany
        );
    }
}
