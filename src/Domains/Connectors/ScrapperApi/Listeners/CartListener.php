<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\AddCostToCartAction;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;

class CartListener
{
    public function handle(array $item): void
    {
        $app = app(Apps::class);

        if (! $app->get(ShippingCostEnum::LOCOMPRO_COST->value)) {
            return;
        }

        $cart = app('cart')->session($item['session_key']);
        $action = new AddCostToCartAction($app, $cart, $item);
        $action->execute();
    }
}
