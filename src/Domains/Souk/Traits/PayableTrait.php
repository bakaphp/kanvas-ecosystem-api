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

    public function hasProcessingPayment(): bool
    {
        return $this->payments()
            ->where('status', PaymentStatusEnum::PROCESSING->value)
            ->exists();
    }

    public function getPaidAmount(): float
    {
        $paidAmount = $this->payments()->where('status', PaymentStatusEnum::PAID->value)->sum('amount');

        return (float) $paidAmount;
    }
}
