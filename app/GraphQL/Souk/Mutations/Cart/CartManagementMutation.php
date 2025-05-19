<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Cart;

use Illuminate\Support\Facades\App;
use Joelwmale\Cart\CartCondition;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Cart\Actions\AddToCartAction;
use Kanvas\Souk\Cart\Services\CartService;

class CartManagementMutation
{
    public function add(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user ? $user->getCurrentCompany() : app(CompaniesBranches::class)->company;

        if (! $company) {
            throw new ModelNotFoundException('No company found');
        }

        $currentUserCompany = $company;
        $app = app(Apps::class);
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));
        $addToCartAction = new AddToCartAction($app, $currentUserCompany, $user);

        return $addToCartAction->execute($cart, $request['items']);
    }

    public function update(mixed $root, array $request): array
    {
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

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
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        $cart->remove($request['variant_id']);

        return $cart->getContent()->toArray();
    }

    public function discountCodesUpdate(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));
        $app = app(Apps::class);

        /**
         * @todo add https://github.com/wearepixel/laravel-cart#adding-a-condition-to-the-cart-cartcondition
         */
        $discountCodes = $request['discountCodes'];
        $isDevelopment = App::environment('development');

        /**
         * @todo FOR THE LOVE OF GOD!! MOVE this to a specific module
         */
        if (! empty($discountCodes) && $app->get('temp-use-discount-codes')) {
            if (strtolower($discountCodes[0]) !== 'aeroambupromoq2' && strtolower($discountCodes[0]) !== 'simlimitesb2b15kv') {
                throw new ModelNotFoundException('Discount code not found');
            }

            if (strtolower($discountCodes[0]) === 'aeroambupromoq2') {
                $discountVariantId = $app->get('temp-discount-variant-id') ?? [];
                $discountVariant = null;
                foreach ($cart->getContent() as $item) {
                    if (in_array($item->id, $discountVariantId)) {
                        $discountVariant = $item;

                        break;
                    }
                }

                if ($discountVariant !== null) {
                    $itemPrice = $app->get('temp-discount-variant-price') ?? '1.00';

                    $tenPercentOff = new CartCondition([
                      'name' => 'aeroambupromoq2',
                      'type' => 'discount',
                      'target' => 'subtotal',
                      'value' => '-' . $itemPrice,
                      'minimum' => 1,
                      'order' => 1,
                    ]);

                    $cart->condition($tenPercentOff);
                }
            } elseif (strtolower($discountCodes[0]) === 'simlimitesb2b15kv') {
                $fifteenPercentOff = new CartCondition([
                  'name' => 'simlimitesb2b15kv',
                  'type' => 'discount',
                  'target' => 'subtotal',
                  'value' => '-15%',
                  'minimum' => 1,
                  'order' => 1,
                ]);

                $cart->condition($fifteenPercentOff);
            }
        }

        $cartService = new CartService($cart);

        return $cartService->getCart();
    }

    public function clear(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));
        $cart->clearAllConditions();

        return $cart->clear();
    }
}
