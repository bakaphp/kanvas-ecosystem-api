<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Actions\CreatePasoRapidoOrderAction;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Kanvas\Workflow\Enums\IntegrationsEnum;

class ProcessPaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment,
        protected Order $order
    ) {
    }

    public function execute(ConsumerAuthentication $consumerData): array
    {
        $paymentProcessor = new PortalPaymentProcessor(
            $this->app,
            $this->payment->company,
            []
        );

        $result = [
            'status' => 'success',
            'message' => 'Payment processed successfully',
            'data' => [],
        ];

        $paymentResult = $paymentProcessor->processPayment($this->payment, $consumerData, $this->order);

        if ($paymentResult['status'] === 'error') {
            $result['status'] = $paymentResult['status'];
            $result['message'] = $paymentResult['message'];
            $result['data'] = $paymentResult['data'];

            return $result;
        }

        if ($paymentResult['status'] === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
            $this->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($paymentResult));
        }

        if ($this->order->orderType->name === IntegrationsEnum::PASO_RAPIDO->value) {
            $createPasoRapidoOrderAction = new CreatePasoRapidoOrderAction($this->app, $this->order);
            $response = $createPasoRapidoOrderAction->execute();

            $result['message'] = $response['message'];
            $result['data'] = $response['data'];
        } else {
            $this->order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
        }

        $intentId = $this->order->fresh()->get(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
        $bankTransaction = explode(':', $intentId)[1];
        if ($this->order->get(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value)) {
            $paymentProcessor->capturePayment($this->payment, $this->order, $bankTransaction);
            if ($orderStatus = $this->order->orderType?->statuses()->where('slug', PaymentStatusEnum::PAID->value)->first()) {
                new TransitionOrderStateAction(
                    $this->order,
                    $orderStatus,
                    $this->order->user
                )->execute(true);
            }
        } else {
            $reason = $result['message'];
            $response = $paymentProcessor->reversePayment($this->payment, $this->order, $bankTransaction, $reason);
            $result['status'] = PaymentStatusEnum::FAILED->value;
            $result['message'] = $response['message'] . ' - ' . $reason;
            $result['data'] = $response['data'];
        }

        return $result;
    }
}
