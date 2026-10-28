<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Listeners;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Actions\EstimateCartTaxAction;
use Wearepixel\Cart\Cart;

class RecalculateCartTaxListener
{
    public function handle(array $eventData, Cart $cart): void
    {
        new EstimateCartTaxAction($cart, app(Apps::class))->executeFailOpen();
    }
}
