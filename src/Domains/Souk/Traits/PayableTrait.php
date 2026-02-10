<?php

declare(strict_types=1);

namespace Kanvas\Souk\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

trait PayableTrait
{
    public function payments(): MorphMany
    {
        return $this->morphMany(Payments::class, 'payable')->latest();
    }

    public function isPaid(): bool
    {
        return $this->getPaidAmount() >= $this->total_net_amount;
    }

    public function hasAuthorizedPayment(): bool
    {
        return $this->payments()
            ->where('status', PaymentStatusEnum::AUTHORIZED->value)
            ->where('amount', '>=', $this->getTotalAmount())
            ->exists();
    }

    public function getPaidAmount(): float
    {
        $paidAmount = $this->payments()->where('status', PaymentStatusEnum::PAID->value)->sum('amount');

        return (float) $paidAmount;
    }

    /**
     * Get the payment method type from the latest paid payment.
     * Checks for actual paid payment records, regardless of payment_status.
     * Defaults to 'card' if payment exists but method is not set.
     */
    public function paymentMethodType(): ?string
    {
        $latestPaidPayment = $this->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        // If no paid payment exists, return null
        if (! $latestPaidPayment) {
            return null;
        }

        // If payment exists but method is null/empty, default to 'card'
        return $latestPaidPayment->payment_method ?: 'card';
    }

    /**
     * Get payment type (physical or digital) based on payment method.
     * Checks for actual paid payment records or fulfillment status.
     * Returns null if order has no paid payments and is not fulfilled.
     */
    public function paymentType(): ?string
    {
        // Check if order has paid payment records OR is fulfilled (paid orders are auto-fulfilled)
        $hasPaidPayment = $this->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->exists();

        if (! $hasPaidPayment && $this->fulfillment_status !== 'fulfilled') {
            return null;
        }

        // Get the payment method to determine if it's digital or physical
        $paymentMethod = $this->paymentMethodType();

        // If no payment method found but order is fulfilled, default to physical
        if (! $paymentMethod) {
            return 'physical';
        }

        // Digital payment methods: card, wallet, payment
        $digitalMethods = ['card', 'wallet', 'payment'];

        // Physical payment methods: cash, bank_transfer, manual
        return in_array($paymentMethod, $digitalMethods) ? 'digital' : 'physical';
    }
}
