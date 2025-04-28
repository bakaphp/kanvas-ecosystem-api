<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Illuminate\Support\Facades\Log;
class CalculateShippingCostAction
{
    public function __construct(
        protected Apps $app,
        protected Variants $variant,
        protected float $quantity
    ) {
    }

    public function execute(): array
    {
        $pounds = $this->variant->getAttributeByName(ConfigurationEnum::WEIGHT_UNIT->value)->value / 453.59237;
        $pounds = $pounds * $this->quantity;
        $price = $this->variant->getPriceInfoFromDefaultChannel()->price;

        // LoCompro Cost
        $deliveryCost = (float)($this->app->get(ShippingCostEnum::DELIVERY_COST_LAST_MILE->value) ?? 2.50);
        $courierCost = (float)($this->app->get(ShippingCostEnum::COURIER_COST->value) ?? 1.30);
        $fuel = (float)($this->app->get(ShippingCostEnum::FUEL->value) ?? 1.02);
        $customService = (float)($this->app->get(ShippingCostEnum::CUSTOM_SERVICE->value) ?? 0.15);
        $airportFee = (float)($this->app->get(ShippingCostEnum::AIRPORT_FEE->value) ?? 0.07);
        $insurance = match (true) {
            $price <= 100 => $price * 0.013,
            $price <= 200 => $price * 0.0160,
            $price > 300 => $price * 0.30,
        };
        $localTransfer = (float)($this->app->get(ShippingCostEnum::LOCAL_TRANSFER->value) ?? 0.00);
        $paymentFee = (float)($this->app->get(ShippingCostEnum::PAYMENT_FEE->value) ?? 0.029);
        $serviceFee = (float)($this->app->get(ShippingCostEnum::SERVICE_FEE->value) ?? 1.90);
        $shippingMargin = (float)($this->app->get(ShippingCostEnum::SHIPPING_MARGIN->value) ?? 1.20);

        // Calculate
        $courierCostWeight = $pounds * $courierCost;
        $costFuel = $pounds * $fuel;
        $customServiceCost = $pounds * $customService;
        $airportFeeCost = $airportFee * $pounds;
        $insuranceCost = $insurance;

        $shippingCost = $courierCostWeight * $shippingMargin;
        $otherFee = $costFuel + $customServiceCost + $airportFeeCost + $insuranceCost;
        $serviceFeeCost = $pounds * $serviceFee;
        $totalLoCompro = $shippingCost + $otherFee + $serviceFeeCost;
        $paymentFeeCost = (($price + $totalLoCompro) * $paymentFee) + 3;
        return [
            'shippingCost' => $shippingCost,
            'otherFee' => $otherFee,
            'serviceFee' => $serviceFeeCost,
            'total' => $totalLoCompro,
        ];
    }
}
