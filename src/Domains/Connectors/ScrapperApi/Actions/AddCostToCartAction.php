<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Wearepixel\Cart\Cart;
use Wearepixel\Cart\CartCondition;

class AddCostToCartAction
{
    public function __construct(
        protected Apps $app,
        protected Cart $cart,
        protected array $item
    ) {
    }

    public function execute(): void
    {
        if (! $this->app->get(ShippingCostEnum::LOCOMPRO_COST->value)) {
            return;
        }

        // Check if cart subtotal is over $200 USD for custom tax calculation
        $cartSubtotal = $this->cart->getSubTotalWithoutConditions(false);
        $shouldCalculateCustomTax = $cartSubtotal > 200;

        $fees = array_map(function ($item) use ($shouldCalculateCustomTax) {
            $variant = $this->findVariant($item['id']);
            $calc = $this->calculateShipping($variant, (float) $item['quantity']);

            // Calculate custom tax only if cart value is over $200 USD
            if ($shouldCalculateCustomTax) {
                $customTaxCalc = $this->calculateCustomTax(
                    $variant,
                    (float) $item['quantity'],
                    $calc['shippingCost'],
                    $calc['insurance'],
                );
                $calc['customTax'] = $customTaxCalc['customTax'];
                $calc['customTaxInfo'] = $customTaxCalc;
            } else {
                $calc['customTax'] = 0;
                $calc['customTaxInfo'] = [
                    'customTax' => 0,
                    'arancelCode' => null,
                    'countryOrigin' => 'US',
                    'calculation' => 'Custom tax not calculated - cart value under $200 USD',
                ];
            }

            return $calc;
        }, $this->cart->getContent()->toArray());

        $fee = collect($fees);
        $customTaxTotal = $fee->sum('customTax');
        $shippingCost = (float) ($this->app->get(ShippingCostEnum::SHIPPING_HANDLING_FEE->value) ?? 2.50);
        $airportFee = (float) ($this->app->get(ShippingCostEnum::AIRPORT_FEE->value) ?? 0.07);
        $customsFee = (float) ($this->app->get(ShippingCostEnum::CUSTOM_SERVICE->value) ?? 0.15);
        $fuelSurcharge = (float) ($this->app->get(ShippingCostEnum::FUEL->value) ?? 1.02);
        $insurance = $cartSubtotal > 100 ? $cartSubtotal * 0.016 : 0.0;
        $otherFees = $airportFee + $customsFee + $fuelSurcharge + $insurance;
        $serviceFee = (float) ($this->app->get(ShippingCostEnum::SERVICE_FEE->value) ?? 3.50);
        $total = $shippingCost + $otherFees + $serviceFee;

        // Collect detailed tax breakdown
        $customTaxDetails = $fee->where('customTaxInfo.customTax', '>', 0)
            ->pluck('customTaxInfo')
            ->map(function ($taxInfo) {
                return [
                    'productName' => $taxInfo['productName'] ?? '',
                    'arancelCode' => $taxInfo['arancelCode'] ?? null,
                    'countryOrigin' => $taxInfo['countryOrigin'] ?? 'US',
                    'customTax' => $taxInfo['customTax'] ?? 0,
                    'customTaxRD' => $taxInfo['customTaxRD'] ?? 0,
                    'arancel' => $taxInfo['arancel'] ?? 0,
                    'arancelRD' => $taxInfo['arancelRD'] ?? 0,
                    'arancelRate' => $taxInfo['arancelRate'] ?? 0,
                    'itbis' => $taxInfo['itbis'] ?? 0,
                    'itbisRD' => $taxInfo['itbisRD'] ?? 0,
                    'itbisRate' => $taxInfo['itbisRate'] ?? 18,
                    'tasaAduanal' => $taxInfo['tasaAduanal'] ?? 0,
                    'tasaAduanalRD' => $taxInfo['tasaAduanalRD'] ?? 0,
                    'tasaAduanalRate' => $taxInfo['tasaAduanalRate'] ?? 0,
                    'isc' => $taxInfo['isc'] ?? 0,
                    'iscRD' => $taxInfo['iscRD'] ?? 0,
                    'iscDescription' => $taxInfo['iscDescription'] ?? '',
                ];
            })
            ->toArray();

        // Calculate totals for each tax component
        $totalArancel = $fee->sum('customTaxInfo.arancel');
        $totalItbis = $fee->sum('customTaxInfo.itbis');
        $totalTasaAduanal = $fee->sum('customTaxInfo.tasaAduanal');
        $totalIsc = $fee->sum('customTaxInfo.isc');

        $this->cart->removeCartCondition('Shipping');
        $condition = new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+' . ($total + $customTaxTotal),
            'attributes' => [
                'Shipping Cost' => $shippingCost,
                'Other Fees' => $otherFees,
                'Service Fee' => $serviceFee,
                'Insurance Fee' => $insurance,
                'Pounds' => $fee->sum('pounds'),
                'Last Mile' => 0,
                'Custom Tax' => $customTaxTotal,
                'Custom Tax Details' => $customTaxDetails,
                'Tax Breakdown' => [
                    'Total Arancel' => $totalArancel,
                    'Total ITBIS' => $totalItbis,
                    'Total Tasa Aduanal' => $totalTasaAduanal,
                    //'Total ISC' => $totalIsc,
                ],
            ],
        ]);
        $this->cart->condition([$condition]);
    }

    protected function findVariant(int|string $id): Variants
    {
        return Variants::getById($id);
    }

    protected function calculateShipping(Variants $variant, float $quantity): array
    {
        return new CalculateShippingCostAction($this->app, $variant, $quantity)->execute();
    }

    protected function calculateCustomTax(
        Variants $variant,
        float $quantity,
        float $freight,
        float $insurance
    ): array {
        return new CalculateCustomTaxAction(
            $variant,
            $quantity,
            $freight,
            $insurance,
        )->execute();
    }
}
