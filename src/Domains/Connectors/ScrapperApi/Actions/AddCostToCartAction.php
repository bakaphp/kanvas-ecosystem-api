<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Joelwmale\Cart\Cart;
use Joelwmale\Cart\CartCondition;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Models\Variants;

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
        $cartSubtotal = $this->cart->getSubTotal();
        $shouldCalculateCustomTax = $cartSubtotal > 200;

        $fees = array_map(function ($item) use ($shouldCalculateCustomTax) {
            $variant = Variants::getById($item['id']);
            $calc = (new CalculateShippingCostAction($this->app, $variant, (float) $item['quantity']))->execute();

            // Calculate custom tax only if cart value is over $200 USD
            if ($shouldCalculateCustomTax) {
                $customTaxCalc = (new CalculateCustomTaxAction($this->app, $variant, (float) $item['quantity'], $item))->execute();
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
        $total = $fee->sum('total');
        $customTaxTotal = $fee->sum('customTax');

        // Add 15% service fee to shipping only, not custom tax
        $total = $total + $total * 0.15;

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
                    'tasaAduanalRate' => $taxInfo['tasaAduanalRate'] ?? 3,
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
                'Shipping Cost' => $fee->sum('shippingCost'),
                'Other Fees' => $fee->sum('otherFee'),
                'Service Fee' => $fee->sum('serviceFee'),
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
}
