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

    public function execute()
    {
        if (! $this->app->get(ShippingCostEnum::LOCOMPRO_COST->value)) {
            return;
        }
        $fees = array_map(function ($item) {
            $variant = Variants::getById($item['id']);
            $calc = (new CalculateShippingCostAction($this->app, $variant, (float) $item['quantity']))->execute();

            return $calc;
        }, $this->cart->getContent()->toArray());
        $fee = collect($fees);
        $total = $fee->sum('total');
        $this->cart->removeCartCondition('Shipping');
        $condition = new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+' . $total,
            'attributes' => [
                'Shipping Cost' => $fee->sum('shippingCost'),
                'Other Fees' => $fee->sum('otherFee'),
                'Service Fee' => $fee->sum('serviceFee'),
                'Last Mile' => 0,
                'Custom Tax' => 0,
            ],
        ]);
        $this->cart->condition([$condition]);
    }
}
