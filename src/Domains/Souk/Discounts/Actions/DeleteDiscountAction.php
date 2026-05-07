<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Kanvas\Souk\Discounts\Models\Discount;

class DeleteDiscountAction
{
    public function __construct(
        protected Discount $discount
    ) {
    }

    public function execute(): bool
    {
        $this->discount->is_deleted = true;
        $this->discount->saveOrFail();

        return true;
    }
}
