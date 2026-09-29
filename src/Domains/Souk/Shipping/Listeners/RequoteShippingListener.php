<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Shipping\Actions\RequoteCartShippingAction;
use Kanvas\Souk\Shipping\Actions\ResolveShippingContextAction;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Throwable;
use Wearepixel\Cart\Cart;

class RequoteShippingListener
{
    public function handle(array $eventData, Cart $cart): void
    {
        $app = app(Apps::class);

        if ($app->get(ShippingCostEnum::LOCOMPRO_COST->value)) {
            return;
        }

        if (RequoteCartShippingAction::providerSelection($cart) === []) {
            return;
        }

        try {
            $context = new ResolveShippingContextAction(
                app: $app,
                user: auth()->user(),
                branch: app()->bound(CompaniesBranches::class) ? app(CompaniesBranches::class) : null,
            )->execute();

            if ($context === null) {
                return;
            }

            new RequoteCartShippingAction(
                app: $context->app,
                company: $context->company,
                region: $context->region,
                cart: $cart,
            )->execute();
        } catch (Throwable $exception) {
            if (! $exception instanceof ValidationException) {
                report($exception);
            }

            $cart->removeCartCondition(ShippingConditionEnum::NAME->value);
        }
    }
}
