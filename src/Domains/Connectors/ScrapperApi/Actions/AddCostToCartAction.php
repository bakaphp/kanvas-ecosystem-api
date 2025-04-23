<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Joelwmale\Cart\CartCondition;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;

class AddCostToCartAction
{
    public function __construct(
        protected Apps $app,
        protected array $item
    ) {
    }

    public function execute()
    {
        $cart = app('cart')->session($this->item['session_key']);
        $fees = array_map(function ($item) {
            $variant = Variants::getById($item['id']);
            $calc = (new CalculateShippingCostAction($variant, (float)$this->item['item']['quantity']))->execute();
            return $calc;
        }, $cart->getContent()->toArray());
        $fee = collect($fees);
        $total = $fee->sum('total');
        $cart->removeCartCondition("Shipping");
        $condition = new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+' . $total,
            'attributes' => [
                'Shipping Cost' => $fee->sum('shippingCost'),
                'Other Fees' => $fee->sum('otherFee'),
                'Service Fee' => $fee->sum('serviceFee'),
            ],
        ]);
        $cart->condition([$condition]);
    }
}
