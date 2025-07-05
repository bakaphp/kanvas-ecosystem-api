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
                //'Custom Tax Details' => $fee->where('customTaxInfo.customTax', '>', 0)->pluck('customTaxInfo')->toArray(),
            ],
        ]);
        $this->cart->condition([$condition]);
    }
}
