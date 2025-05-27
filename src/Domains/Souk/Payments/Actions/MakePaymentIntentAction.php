<?php

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\AuthorizePortalPaymentProcessor;

class MakePaymentIntentAction
{
    public function __construct(
        protected Payments $payment,
    ) {
    }

    public function execute(): mixed
    {
        $paymentProcessor = new AuthorizePortalPaymentProcessor(
            $this->payment->app,
            $this->payment->company
        );

        return $paymentProcessor->makePaymentIntent($this->payment);
    }
}
