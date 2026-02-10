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
     * Get the payment method type from the latest paid payment
     */
    public function paymentMethodType(): string
    {
        $latestPaidPayment = $this->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        return $latestPaidPayment?->payment_method ?? 'card';
    }

    /**
     * Get payment type (physical or digital) based on payment method.
     * Returns null if order payment_status is not 'paid'.
     */
    public function paymentType(): ?string
    {
        // Only return payment type if order payment_status is 'paid'
        if ($this->payment_status !== 'paid') {
            return null;
        }

        // Get the payment method to determine if it's digital or physical
        $paymentMethod = $this->paymentMethodType();

        // Digital payment methods: card, wallet, payment
        $digitalMethods = ['card', 'wallet', 'payment'];

        // Physical payment methods: cash, bank_transfer, manual
        return in_array($paymentMethod, $digitalMethods) ? 'digital' : 'physical';
    }
}
