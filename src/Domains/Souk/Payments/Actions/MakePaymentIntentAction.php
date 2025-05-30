<?php

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Connectors\EchoPay\Workflows\Activities\ProcessPaymentActivity;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Models\StoredWorkflow;

class MakePaymentIntentAction
{
    public function __construct(
        protected Payments $payment,
    ) {
    }

    public function execute(): mixed
    {
        // $paymentProcessor = new AuthorizePortalPaymentProcessor(
        //     $this->payment->app,
        //     $this->payment->company
        // );

        // return $paymentProcessor->makePaymentIntent($this->payment);
        $activity = new ProcessPaymentActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            [
                'app' => $this->payment->order->app,
            ]
        );
        $activity->execute($this->payment, $this->payment->order->app, []);

        return $this->payment;
    }
}
