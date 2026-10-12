<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;

class SettleInsurancePaymentAction
{
    public function __construct(
        protected Order $order,
    ) {
    }

    public function capture(): ?bool
    {
        $payment = $this->heldPayment();

        if ($payment === null) {
            return null;
        }

        return $this->processor($payment)->capture($payment, $this->order)->success;
    }

    public function release(): ?bool
    {
        $payment = $this->heldPayment();

        if ($payment === null) {
            return null;
        }

        return $this->processor($payment)->void($payment, $this->order)->success;
    }

    protected function heldPayment(): ?Payments
    {
        return $this->order->payments()
            ->where('status', PaymentStatusEnum::AUTHORIZED->value)
            ->orderByDesc('id')
            ->first();
    }

    protected function processor(Payments $payment): PaymentProcessorInterface
    {
        return ProcessorFactory::make(
            $payment->paymentMethod?->processor ?? $payment->processor,
            $this->order->app,
            $this->order->company
        );
    }
}
