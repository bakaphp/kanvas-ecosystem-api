<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantPriceService;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Joelwmale\Cart\Cart;

class AddToCartAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?Users $user=null,
    ) {
    }

    public function execute(Cart $cart, array $items): array
    {
        $company = B2BConfigurationService::getConfiguredB2BCompany($this->app, $this->company);
        $currentUserCompany = $company;

        //@todo send warehouse via header
        //$useCompanySpecificPrice = $app->get(ConfigurationEnum::COMPANY_CUSTOM_CHANNEL_PRICING->value) ?? false;

        $variantPriceService = new VariantPriceService($this->app, $currentUserCompany);
        foreach ($items as $item) {
            $variant = Variants::getByIdFromCompany($item['variant_id'], $company);
            $channelId = $item['channel_id'] ?? null;

            //$variantPrice = $variant->variantWarehouses()->firstOrFail()->price;
            /*                $variantPrice = $useCompanySpecificPrice
                                  ? $variant->variantChannels()
                                      ->whereHas('channel', fn ($query) => $query->where('slug', $currentUserCompany->uuid))
                                      ->firstOrFail()->price
                                  : $variant->getPriceInfoFromDefaultChannel()->price;
              */
            $variantPrice = $variantPriceService->getPrice($variant, $channelId);
            $cart->add([
                'id' => $variant->getId(),
                'name' => $variant->name,
                'price' => $variantPrice, //@todo modify to use channel instead of warehouse
                'quantity' => $item['quantity'],
                'attributes' => $variant->product->attributes ? $variant->product->attributes->map(function ($attribute) {
                    return [
                        $attribute->name => $attribute->value,
                    ];
                })->collapse()->all() : [],
                //'associatedModel' => $Product,
            ]);
        }

        return $cart->getContent()->toArray();
    }
}
