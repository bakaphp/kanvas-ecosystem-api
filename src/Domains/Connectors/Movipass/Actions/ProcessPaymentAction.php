<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Exception;
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

        $orderTypeName = $this->order->orderType?->name;
        $isPasoRapido = $orderTypeName === IntegrationsEnum::PASO_RAPIDO->value;

        if ($isPasoRapido) {
            $createPasoRapidoOrderAction = new CreatePasoRapidoOrderAction($this->app, $this->order);
            $response = $createPasoRapidoOrderAction->execute();

            $result['message'] = $response['message'];
            $result['data'] = $response['data'] ?? [];
        } else {
            $this->order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
        }

        $intentId = $this->order->fresh()->get(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
        $bankTransaction = explode(':', $intentId)[1];
        $shouldCaptureValue = $this->order->get(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value);

        $this->payment->addLog('capture_decision', [
            'order_id' => $this->order->id,
            'order_type' => $orderTypeName,
            'is_paso_rapido' => $isPasoRapido,
            'intent_id' => $intentId,
            'should_capture' => $shouldCaptureValue,
            'should_capture_type' => gettype($shouldCaptureValue),
            'will_capture' => (bool) $shouldCaptureValue,
        ]);

        if (! $shouldCaptureValue) {
            return $this->handleReversal($paymentProcessor, $bankTransaction, $result['message']);
        }

        $captureResult = $paymentProcessor->capturePayment($this->payment, $this->order, $bankTransaction);

        if ($captureResult['status'] === 'error') {
            return $this->handleReversal($paymentProcessor, $bankTransaction, 'Capture failed: ' . $captureResult['message']);
        }

        try {
            if ($orderStatus = $this->order->orderType?->statuses()->where('slug', PaymentStatusEnum::PAID->value)->first()) {
                new TransitionOrderStateAction(
                    $this->order,
                    $this->payment->user,
                    $orderStatus
                )->execute(true);
            }

            new SendPaymentReceiptAction(
                $this->order,
                $this->payment,
                $this->payment->user
            )->execute();
        } catch (Exception $e) {
            report($e);
        }

        return $result;
    }

    private function handleReversal(PortalPaymentProcessor $paymentProcessor, string $bankTransaction, string $reason): array
    {
        $this->payment->addLog('payment_reversal', [
            'order_id' => $this->order->id,
            'bank_transaction' => $bankTransaction,
            'reason' => $reason,
        ]);

        $response = $paymentProcessor->reversePayment($this->payment, $this->order, $bankTransaction, $reason);

        return [
            'status' => PaymentStatusEnum::FAILED->value,
            'message' => $response['message'] . ' - ' . $reason,
            'data' => $response['data'],
        ];
    }
}
