<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Cart;

use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantPriceService;
use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Wearepixel\Cart\CartCondition;

class CartManagementMutation
{
    public function add(mixed $root, array $request): array
    {
        $items = $request['items'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $currentUserCompany = $company;
        $cart = app('cart')->session($user->getId());
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

    public function update(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        if (! $cart->has($request['variant_id'])) {
            return [];
        }

        $cart->update($request['variant_id'], [
            'quantity' => $request['quantity'],
        ]);

        return $cart->getContent()->toArray();
    }

    public function remove(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        $cart->remove($request['variant_id']);

        return $cart->getContent()->toArray();
    }

    public function discountCodesUpdate(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        /**
         * @todo add https://github.com/wearepixel/laravel-cart#adding-a-condition-to-the-cart-cartcondition
         */
        $discountCodes = $request['discountCodes'];
        $isDevelopment = App::environment('development');

        /**
         * @todo temp condition for development so they can test
         */
        if ($isDevelopment && ! empty($discountCodes)) {
            if (strtolower($discountCodes[0]) !== 'kanvas') {
                throw new ModelNotFoundException('Discount code not found');
            }

            $tenPercentOff = new CartCondition([
              'name' => 'KANVAS',
              'type' => 'discount',
              'target' => 'subtotal',
              'value' => '-10%',
              'minimum' => 1,
              'order' => 1,
                    ]);

            $cart->condition($tenPercentOff);
        }

        $cartService = new CartService($cart);

        return $cartService->getCart();
    }

    public function clear(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());
        $cart->clearAllConditions();

        return $cart->clear();
    }
}
