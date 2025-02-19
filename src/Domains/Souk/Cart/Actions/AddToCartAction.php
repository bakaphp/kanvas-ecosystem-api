<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantPriceService;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;

class AddToCartAction
{
    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected Companies $company
    ) {
    }

    public function execute(mixed $cart, array $items): array
    {
        $company = $this->company;
        $currentUserCompany = $company;
        $app = app(Apps::class);

        /**
         * @todo for now for b2b store clients
         * change this to use company group?
         */
        if ($app->get('USE_B2B_COMPANY_GROUP')) {
            if (UserCompanyApps::where('companies_id', $app->get('B2B_GLOBAL_COMPANY'))->where('apps_id', $app->getId())->first()) {
                $company = Companies::getById($app->get('B2B_GLOBAL_COMPANY'));
            }
        }

        //@todo send warehouse via header
        //$useCompanySpecificPrice = $app->get(ConfigurationEnum::COMPANY_CUSTOM_CHANNEL_PRICING->value) ?? false;

        $variantPriceService = new VariantPriceService($app, $currentUserCompany);
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
                        $attribute->name => $attribute->pivot->value,
                    ];
                })->collapse()->all() : [],
                //'associatedModel' => $Product,
            ]);
        }

        return $cart->getContent()->toArray();
    }
}
