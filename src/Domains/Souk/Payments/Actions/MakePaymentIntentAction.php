<?php

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Souk\Payments\Models\Payments;

class MakePaymentIntentAction
{
    public function __construct(
        protected Payments $payment,
    ) {
    }

    // @deprecated: It will be removed in the next version
    public function execute(): mixed
    {
        return $this->payment;
    }
}
