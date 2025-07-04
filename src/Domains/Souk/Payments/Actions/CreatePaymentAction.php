<?php

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreatePaymentAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected Order $order,
        protected Users $user
    ) {
    }

    public function execute($formData = []): Payments
    {
        $paymentMethodId = $formData['payment_methods_id'] ?? $this->order->payment_method_id;
        $paymentMethod = PaymentMethods::fromApp($this->order->app)->where('id', $paymentMethodId)->first();

        if (! $paymentMethod) {
            throw new \Exception('Payment method not found');
        }

        if ($this->order->isPaid()) {
            throw new \Exception('Order already paid');
        }

        if ($this->hasPendingPayments()) {
            $this->order->payments()->pending()->delete();
        }

        $paymentFormData = [
            "amount" => $formData['amount'] ?? $this->order->getTotalAmount(),
            "payment_date" => $formData['payment_date'] ?? date("Y-m-d"),
            "concept" => $formData['concept'] ?? "Payment {$this->order->reference}",
            "payment_methods_id" => $paymentMethodId,
            'users_id' => $this->user->getId(),
            'companies_id' => $this->order->companies_id,
            'currency' => $this->order->currency,
            'status' => PaymentStatusEnum::PENDING->value
        ];

        $payment = $this->order->payments()->create($paymentFormData);
        $this->order->updateQuietly([
            'status' => OrderStatusEnum::PENDING->value,
        ]);

        if (isset($formData['order_metadata'])) {
            $this->order->metadata = [
                ...($this->order->metadata ?? []),
                'data' => [
                    ...($this->order->metadata['data'] ?? []),
                    ...($formData['order_metadata']['data'] ?? []),
                ]
            ];
            $this->order->saveQuietly();
        }

        if ($this->runWorkflow) {
            $payment->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true,
                [
                    'app' => $this->order->app,
                ]
            );
        }
        return $payment;
    }

    public function hasPendingPayments(): bool
    {
        return $this->order->payments()->where('status', PaymentStatusEnum::PENDING->value)->exists();
    }
}
