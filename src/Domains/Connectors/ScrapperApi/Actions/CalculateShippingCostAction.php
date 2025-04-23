<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;

class CalculateShippingCostAction
{
    public function __construct(
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
        $deliveryCost = 2.50;
        $courierCost = 1.30;
        $fuel = 1.02;
        $customService = 0.15;
        $airportFee = 0.07;
        $insurance = match (true) {
            $price <= 100 => $price * 0.013,
            $price <= 200 => $price * 0.0160,
            $price > 300 => $price * 0.30,
        };
        $localTransfer = 0.00;
        $paymentFee = $price * 0.029;
        $serviceFee = 1.90;
        $shippingMargin = 1;

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
            "shippingCost" => $shippingCost,
            'otherFee' => $otherFee,
            'serviceFee' => $serviceFeeCost,
            "total" => $totalLoCompro
        ];        
    }
}
