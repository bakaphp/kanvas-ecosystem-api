<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;

class CapturePaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment,
        protected Order $order
    ) {
    }

    public function execute(bool $sendEmail = true): array
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

        $intentId = $this->order->get(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
        $bankTransaction = explode(':', $intentId)[1];

        $this->payment->addLog('capture_action_executed', [
            'order_id' => $this->order->id,
            'intent_id' => $intentId,
            'bank_transaction' => $bankTransaction,
            'source' => 'CapturePaymentAction',
        ]);

        $captureResult = $paymentProcessor->capturePayment($this->payment, $this->order, $bankTransaction);

        if ($captureResult['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => $captureResult['message'],
                'data' => $captureResult['data'] ?? [],
            ];
        }

        try {
            $this->order->markAsPaid($this->payment->user);

            if ($sendEmail) {
                new SendPaymentReceiptAction(
                    $this->order,
                    $this->payment,
                    $this->payment->user
                )->execute();
            }
        } catch (Exception $e) {
            report($e);
        }

        return $result;
    }
}
