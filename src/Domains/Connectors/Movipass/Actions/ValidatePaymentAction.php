<?php

namespace Kanvas\Connectors\Movipass\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

class ValidatePaymentAction
{
    public function __construct(
        protected Apps $app,
        protected Payments $payment,
        protected Order $order
    ) {
    }

    public function execute(): array
    {
        if ($this->payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return [
                'status' => 'error',
                'message' => 'Payment is not in authorized status. Current status: ' . $this->payment->status,
            ];
        }

        try {
            $this->payment->addLog('validate_action_executed', [
                'order_id' => $this->order->id,
                'payment_id' => $this->payment->id,
                'validated_by' => auth()->user()->id ?? null,
                'source' => 'ValidatePaymentAction',
            ]);

            // Mark payment as paid with backup_capture flag
            $this->payment->markAsPaid([
                'data' => [
                    'backup_capture' => true,
                    'manual_validation' => true,
                    'validated_at' => now()->toIso8601String(),
                    'validated_by' => auth()->user()->id ?? null,
                ],
            ]);

            $this->payment->addLog('payment_validated', [
                'order_id' => $this->order->id,
                'payment_id' => $this->payment->id,
                'validated_by' => auth()->user()->id ?? null,
                'validated_at' => now()->toIso8601String(),
            ]);

            // Transition order to paid
            $this->order->markAsPaid(auth()->user());

            return [
                'status' => 'success',
                'message' => 'Payment validated and marked as paid',
                'payment' => $this->payment,
                'order' => $this->order,
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Error validating payment: ' . $e->getMessage(),
            ];
        }
    }
}
