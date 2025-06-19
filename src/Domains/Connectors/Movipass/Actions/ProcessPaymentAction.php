<?php

namespace App\Domains\Connectors\Movipass\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Workflows\Activities\CreatePasoRapidoOrderActivity;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Workflow\Models\StoredWorkflow;

class ProcessPaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment
    ) {
    }

    public function execute(Payments $payment) {
        $paymentProcessor = new PortalPaymentProcessor(
            $this->app,
            $this->payment->company,
            []
        );

        if ($payment->order->orderType->name === IntegrationsEnum::PASO_RAPIDO->value) {
            $createPasoRapidoOrderActivity = new CreatePasoRapidoOrderActivity(
                0,
                now()->toDateTimeString(),
                StoredWorkflow::make(),
                []
            );

            $createPasoRapidoOrderActivity->execute($payment->order, $this->app, []);
        } else {
            $payment->order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
        }

        if ($payment->order->get(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value)) {
            $paymentProcessor->capturePayment($payment, $payment->order->get(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value));
        } else {
            $paymentProcessor->reversePayment($payment, $payment->order->get(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value));
        }
    }
}

