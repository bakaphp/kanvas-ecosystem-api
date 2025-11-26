<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Actions;

use Joelwmale\Cart\Cart;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantPriceService;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Users\Models\Users;

class AddToCartAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?Users $user = null,
    ) {
    }

    public function execute(Cart $cart, array $items): array
    {
        $company = B2BConfigurationService::getConfiguredB2BCompany($this->app, $this->company);
        $currentUserCompany = $this->company; //this has to be the current user company, not the global b2b one
        $allowCrossCompanyVariants = $this->app->get(ConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value) ?? false;

        //@todo send warehouse via header
        //$useCompanySpecificPrice = $app->get(ConfigurationEnum::COMPANY_CUSTOM_CHANNEL_PRICING->value) ?? false;

        $variantPriceService = new VariantPriceService($this->app, $currentUserCompany);
        foreach ($items as $item) {
            // Case 1: Global B2B variant from retailer company (default behavior when B2B is configured)
            // Case 2: Variant from the same company (when B2B is not configured)
            // Case 3: Variant from any company in the app (when ALLOW_CROSS_COMPANY_VARIANTS flag is enabled)
            if ($allowCrossCompanyVariants) {
                $variant = Variants::getById($item['variant_id'], $this->app);
            } else {
                $variant = Variants::getByIdFromCompany($item['variant_id'], $company);
            }
            $channelId = $item['channel_id'] ?? null;

            //$variantPrice = $variant->variantWarehouses()->firstOrFail()->price;
            /*                $variantPrice = $useCompanySpecificPrice
                                  ? $variant->variantChannels()
                                      ->whereHas('channel', fn ($query) => $query->where('slug', $currentUserCompany->uuid))
                                      ->firstOrFail()->price
                                  : $variant->getPriceInfoFromDefaultChannel()->price;
              */
            $variantPrice = $variantPriceService->getPrice($variant, $channelId);

            // Get attributes from existing cart item if it exists, otherwise from product
            $attributes = $cart->has($variant->getId())
                ? $cart->get($variant->getId())['attributes']
                : ($variant->product->attributes ? $variant->product->attributes->map(function ($attribute) {
                    return [
                        $attribute->name => $attribute->value,
                    ];
                })->collapse()->all() : []);

            $cart->add([
                'id' => $variant->getId(),
                'name' => $variant->name,
                'price' => $variantPrice, //@todo modify to use channel instead of warehouse
                'quantity' => $item['quantity'],
                'attributes' => $attributes,
                //'associatedModel' => $Product,
            ]);
        }

        return $cart->getContent()->toArray();
    }
}
