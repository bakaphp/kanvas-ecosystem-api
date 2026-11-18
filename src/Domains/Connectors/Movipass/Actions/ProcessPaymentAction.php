<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Jobs\RetryPaymentReversalJob;
use Kanvas\Connectors\PasoRapido\Actions\CreatePasoRapidoOrderAction;
use Kanvas\Exceptions\ValidationException;
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
        protected Order $order,
        protected ?PortalPaymentProcessor $paymentProcessor = null,
    ) {
    }

    public function execute(ConsumerAuthentication $consumerData): array
    {
        $roadsidePriceError = $this->guardRoadsidePrice();

        if ($roadsidePriceError !== null) {
            return $roadsidePriceError;
        }

        $paymentProcessor = $this->paymentProcessor ?? new PortalPaymentProcessor(
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

        $this->order->markAsPaid($this->payment->user);

        // The card is already charged; a dispatch failure must not report the payment as failed.
        try {
            new DispatchRegionalRoadsideCaseAction($this->order)->execute();
        } catch (Exception $e) {
            report($e);
        }

        try {
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

    /**
     * Asistencia Regional only: for every other order (parking, Paso Rápido, roadside elsewhere) the
     * action returns null before touching anything and the payment runs exactly as before.
     *
     * A regional roadside order is repriced by an async workflow after creation, so the payment may
     * have been opened at the cart price before that lands. Reprice synchronously here, before the card is
     * touched, and refuse any amount that is not the quoted total: the client opens a new payment at
     * the corrected amount instead of being charged the wrong one.
     */
    private function guardRoadsidePrice(): ?array
    {
        try {
            $quote = new ApplyRoadsideQuoteToOrderAction($this->order)->execute();
        } catch (ValidationException $exception) {
            return $this->failBeforeCharging('The roadside assistance order could not be priced: ' . $exception->getMessage());
        }

        if ($quote === null) {
            return null;
        }

        try {
            new AssertRoadsideCaseIsPayableAction($this->order)->execute();
        } catch (ValidationException $exception) {
            return $this->failBeforeCharging($exception->getMessage());
        }

        if (round((float) $this->payment->amount, 2) === $quote->price->total) {
            return null;
        }

        return $this->failBeforeCharging(sprintf(
            'The order total is %.2f but this payment is for %.2f. Start the payment again for the order total.',
            $quote->price->total,
            (float) $this->payment->amount,
        ));
    }

    private function failBeforeCharging(string $message): array
    {
        $this->payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        $this->payment->addLog('roadside_price_rejected', [
            'order_id' => $this->order->id,
            'payment_amount' => (float) $this->payment->amount,
            'order_total' => (float) $this->order->total_net_amount,
            'reason' => $message,
        ]);

        return [
            'status' => 'error',
            'message' => $message,
            'data' => [],
        ];
    }

    private function handleReversal(PortalPaymentProcessor $paymentProcessor, string $bankTransaction, string $reason): array
    {
        $this->payment->addLog('payment_reversal', [
            'order_id' => $this->order->id,
            'bank_transaction' => $bankTransaction,
            'reason' => $reason,
        ]);

        $response = $paymentProcessor->reversePayment($this->payment, $this->order, $bankTransaction, $reason);

        // The gateway failed the reversal: the card still holds the money, so keep the row
        // AUTHORIZED and hand it to the retry job instead of letting it be marked FAILED.
        if ($response['status'] === 'error') {
            RetryPaymentReversalJob::markPending($this->payment, $reason, (string) $response['message']);

            RetryPaymentReversalJob::dispatch($this->app, $this->payment, $this->order, $reason)
                ->delay(now()->addMinute());
        }

        return [
            'status' => PaymentStatusEnum::FAILED->value,
            'message' => $response['message'] . ' - ' . $reason,
            'data' => $response['data'],
        ];
    }
}
