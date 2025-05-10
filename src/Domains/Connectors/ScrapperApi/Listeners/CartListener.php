<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\AddCostToCartAction;

class CartListener
{
    public function handle(array $item): void
    {
        $app = app(Apps::class);
        $cart = app('cart')->session($item['session_key']);
        $action = new AddCostToCartAction($app, $cart, $item);
        $action->execute();
    }
}
