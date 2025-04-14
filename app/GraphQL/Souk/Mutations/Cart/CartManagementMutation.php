<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Cart;

use Illuminate\Support\Facades\App;
use Joelwmale\Cart\CartCondition;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Cart\Actions\AddToCartAction;
use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Enums\AppEnums;

class CartManagementMutation
{
    public function add(mixed $root, array $request): array
    {
        $user = auth()->user();
        $company = $user ? $user->getCurrentCompany() : app(CompaniesBranches::class)->company;
        $currentUserCompany = $company;
        $app = app(Apps::class);
        $cart = app('cart')->session(app(AppEnums::KANVAS_CART_SESSION->getValue()));
        $addToCartAction = new AddToCartAction($app, $currentUserCompany, $user);
        return $addToCartAction->execute($cart, $request['items']);
    }

    public function update(mixed $root, array $request): array
    {
        $cart = app('cart')->session(app(AppEnums::KANVAS_CART_SESSION->getValue()));

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
        $cart = app('cart')->session(app(AppEnums::KANVAS_CART_SESSION->getValue()));

        $cart->remove($request['variant_id']);

        return $cart->getContent()->toArray();
    }

    public function discountCodesUpdate(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session(app(AppEnums::KANVAS_CART_SESSION->getValue()));
        $app = app(Apps::class);

        /**
         * @todo add https://github.com/wearepixel/laravel-cart#adding-a-condition-to-the-cart-cartcondition
         */
        $discountCodes = $request['discountCodes'];
        $isDevelopment = App::environment('development');

        /**
         * @todo for the love of god move this to a specific module
         */
        if (! empty($discountCodes) && $app->get('temp-use-discount-codes')) {
            if (strtolower($discountCodes[0]) !== 'app15') {
                throw new ModelNotFoundException('Discount code not found');
            }

            $tenPercentOff = new CartCondition([
              'name' => 'APP15',
              'type' => 'discount',
              'target' => 'subtotal',
              'value' => '-15%',
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
        $cart = app('cart')->session(app(AppEnums::KANVAS_CART_SESSION->getValue()));
        $cart->clearAllConditions();

        return $cart->clear();
    }
}
