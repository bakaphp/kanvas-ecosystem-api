<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking;

use Baka\Support\AllowedClass;
use Kanvas\Connectors\Movipass\Parking\Contracts\ParkingOperator;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingOrderVariant;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;

final class ParkingOperatorResolver
{
    public const string PRODUCT_SETTING = 'parking_operator';

    public static function forOrder(Order $order): ParkingOperator
    {
        return self::forProduct(ParkingOrderVariant::from($order)->product);
    }

    public static function forProduct(Products $product): ParkingOperator
    {
        $configured = $product->get(self::PRODUCT_SETTING);
        $class = AllowedClass::resolve(
            is_string($configured) && $configured !== '' ? $configured : SimulatedParkingOperator::class,
            ParkingOperator::class
        );

        if ($class === null) {
            throw new ValidationException(sprintf('%s is not an allowed parking operator.', (string) $configured));
        }

        return new $class();
    }
}
