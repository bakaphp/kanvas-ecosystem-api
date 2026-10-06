<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Wearepixel\Cart\Cart;

class RequoteCartShippingAction
{
    public const UNAVAILABLE_MESSAGE = 'Selected shipping service is no longer available';

    public function __construct(
        protected Apps $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected Cart $cart,
    ) {
    }

    public static function providerSelection(Cart $cart): array
    {
        $attributes = $cart->getCondition(ShippingConditionEnum::NAME->value)?->getAttributes() ?? [];

        return empty($attributes[ShippingConditionEnum::PROVIDER->value] ?? null) ? [] : $attributes;
    }

    public function execute(): void
    {
        $attributes = self::providerSelection($this->cart);

        if ($attributes === []) {
            return;
        }

        if ($this->cart->isEmpty()) {
            $this->cart->removeCartCondition(ShippingConditionEnum::NAME->value);

            return;
        }

        new ApplyShippingQuoteToCartAction(
            app: $this->app,
            company: $this->company,
            region: $this->region,
            cart: $this->cart,
            provider: (string) $attributes[ShippingConditionEnum::PROVIDER->value],
            service: (string) ($attributes[ShippingConditionEnum::SERVICE->value] ?? ''),
            destination: ShippingDestination::forInput($attributes[ShippingConditionEnum::DESTINATION->value] ?? []),
            unavailableMessage: self::UNAVAILABLE_MESSAGE,
        )->execute();
    }
}
