<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Observers;

use Kanvas\Souk\Discounts\Models\Discount;

class DiscountObserver
{
    public function creating(Discount $discount): void
    {
    }
}
