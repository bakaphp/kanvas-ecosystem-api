<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use RuntimeException;
use Throwable;

class CaptureOrderPaymentsOnTransitionAction
{
    public function __construct(protected Order $order)
    {
    }

    public function execute(): void
    {
        $payments = $this->order->payments()
            ->whereIn('status', [PaymentStatusEnum::AUTHORIZED->value, PaymentStatusEnum::PAID->value])
            ->get();

        $authorized = $payments->where('status', PaymentStatusEnum::AUTHORIZED->value);

        if ($authorized->isEmpty()) {
            if ($payments->isEmpty()) {
                throw new RuntimeException('The order has no authorized payment to capture');
            }

            return;
        }

        $capturedIds = [];

        foreach ($authorized as $payment) {
            $this->captureOrRefuse($payment, $capturedIds);
            $capturedIds[] = $payment->getId();
        }

        $this->order->refresh();
    }

    private function captureOrRefuse(Payments $payment, array $capturedIds): void
    {
        try {
            $result = $this->capture($payment);
        } catch (Throwable $e) {
            throw new RuntimeException($this->refusal($payment, $e->getMessage(), $capturedIds), previous: $e);
        }

        if (! $result->success) {
            throw new RuntimeException($this->refusal($payment, $result->message, $capturedIds));
        }

        if ($payment->fresh()->status !== PaymentStatusEnum::PAID->value) {
            throw new RuntimeException($this->refusal($payment, 'the processor reported success but the payment is not paid', $capturedIds));
        }
    }

    private function capture(Payments $payment): CaptureResult
    {
        $processor = ProcessorFactory::make(
            $payment->paymentMethod?->processor ?? $payment->processor,
            $this->order->app,
            $payment->company
        );

        return $processor->capture($payment, $this->order, (float) $payment->amount);
    }

    private function refusal(Payments $payment, string $reason, array $capturedIds): string
    {
        $message = "Payment {$payment->getId()} could not be captured: {$reason}";

        if ($capturedIds !== []) {
            $message .= '. Already captured and not reverted: payments ' . implode(', ', $capturedIds);
        }

        return $message;
    }
}
