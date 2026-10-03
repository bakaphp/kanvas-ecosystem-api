<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Cart\Services\CartService;
use Kanvas\Souk\Shipping\Actions\ApplyShippingQuoteToCartAction;
use Kanvas\Souk\Shipping\Actions\ResolveShippingContextAction;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;

class ShippingMutation
{
    public function apply(mixed $root, array $request): array
    {
        $context = new ResolveShippingContextAction(
            app: app(Apps::class),
            user: auth()->user(),
            branch: app()->bound(CompaniesBranches::class) ? app(CompaniesBranches::class) : null,
        )->executeOrFail();
        $cart = app('cart')->session(app(AppEnums::KANVAS_IDENTIFIER->getValue()));

        new ApplyShippingQuoteToCartAction(
            app: $context->app,
            company: $context->company,
            region: $context->region,
            cart: $cart,
            provider: $request['provider'],
            service: $request['service'],
            destination: ShippingDestination::forInput($request['destination']),
        )->execute();

        return new CartService($cart)->getCart();
    }
}
