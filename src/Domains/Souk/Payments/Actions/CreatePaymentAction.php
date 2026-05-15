<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentMethodTypesEnum;
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
        $idempotencyKey = $formData['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = Payments::where('idempotency_key', $idempotencyKey)
                ->where('apps_id', $this->order->apps_id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $paymentMethodId = $formData['payment_methods_id'] ?? $this->order->payment_method_id;
        $paymentMethod = $paymentMethodId ? PaymentMethods::fromApp($this->order->app)->where('id', $paymentMethodId)->first() : null;
        $paymentIntent = $formData['payment_intent_id'] ?? null;
        $paymentMethodType = $formData['payment_method_type'] ?? null;

        $isDirectPaymentMethod = in_array($paymentMethodType, [
            PaymentMethodTypesEnum::CASH->value,
            PaymentMethodTypesEnum::BANK_TRANSFER->value,
        ]);

        if (! $paymentMethod && ! $paymentIntent && ! $isDirectPaymentMethod) {
            throw new Exception('Payment method not found');
        }

        if ($this->order->isPaid()) {
            throw new Exception('Order already paid');
        }

        $paymentCurrency = $formData['currency'] ?? $this->order->currency;

        if (
            $this->order->currency
            && $paymentCurrency
            && strtoupper($paymentCurrency) !== strtoupper($this->order->currency)
        ) {
            throw new ValidationException(
                "Payment currency ({$paymentCurrency}) does not match order currency ({$this->order->currency})"
            );
        }

        if ($this->hasPendingPayments()) {
            $this->order->payments()->pending()->forceDelete();
        }

        $paymentFormData = [
            "amount" => $formData['amount'] ?? $this->order->getTotalAmount(),
            "payment_date" => $formData['payment_date'] ?? date("Y-m-d"),
            "concept" => $formData['concept'] ?? "Payment {$this->order->reference}",
            "payment_methods_id" => $paymentMethodId,
            'payment_intent_id' => $paymentIntent,
            'idempotency_key' => $idempotencyKey,
            'users_id' => $this->user->getId(),
            'companies_id' => $this->order->companies_id,
            'currency' => $paymentCurrency,
            'status' => $formData['status'] ?? PaymentStatusEnum::PENDING->value,
            'payment_method' => $paymentMethodType ?? 'card',
            'payment_method_brand' => $paymentMethod?->payment_methods_brand,
            'payment_method_last_four' => $paymentMethod?->payment_ending_numbers,
            'processor' => $paymentMethod?->processor,
            'metadata' => array_filter([
                'payment_method_type' => $paymentMethodType ?: null,
                'use_hold' => isset($formData['use_hold']) ? (bool) $formData['use_hold'] : null,
            ]),
        ];

        try {
            $payment = $this->order->payments()->create($paymentFormData);
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey) {
                return Payments::where('idempotency_key', $idempotencyKey)
                    ->where('apps_id', $this->order->apps_id)
                    ->firstOrFail();
            }

            throw $e;
        }

        if ($payment->status === PaymentStatusEnum::PAID->value) {
            $this->order->markAsPaid($this->user);
        } else {
            $this->order->updateQuietly([
                'status' => OrderStatusEnum::PENDING->value,
            ]);
        }

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
        return $this->order->payments()->whereIn('status', [PaymentStatusEnum::PENDING->value, PaymentStatusEnum::PENDING_AUTHORIZATION->value])->exists();
    }
}
