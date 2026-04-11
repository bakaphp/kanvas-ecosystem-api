<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class SyncPayablePaymentStatusAction
{
    public function __construct(
        protected Model $payable,
    ) {
    }

    public function execute(): void
    {
        if (! isset($this->payable->payment_status)) {
            return;
        }

        $payments = $this->payable->payments()->get();

        if ($payments->isEmpty()) {
            return;
        }

        $newStatus = $this->computeStatus($payments);

        if ($newStatus && $this->payable->payment_status !== $newStatus) {
            $this->payable->updateQuietly(['payment_status' => $newStatus]);
        }
    }

    protected function computeStatus($payments): ?string
    {
        $totalAmount = (float) ($this->payable->total_net_amount ?? 0);
        $paidAmount = (float) $payments->where('status', PaymentStatusEnum::PAID->value)->sum('amount');
        $refundedAmount = (float) $payments->where('status', PaymentStatusEnum::REVERSED->value)->sum('amount');

        if ($refundedAmount > 0 && $refundedAmount >= $paidAmount) {
            return PaymentStatusEnum::REVERSED->value;
        }

        if ($paidAmount > 0 && $paidAmount >= $totalAmount) {
            return PaymentStatusEnum::PAID->value;
        }

        $hasProcessing = $payments->whereIn('status', [
            PaymentStatusEnum::PROCESSING->value,
            PaymentStatusEnum::WAITING_DEVICE_DATA->value,
            PaymentStatusEnum::PENDING_AUTHORIZATION->value,
        ])->isNotEmpty();

        if ($hasProcessing) {
            return 'processing';
        }

        $hasPending = $payments->where('status', PaymentStatusEnum::PENDING->value)->isNotEmpty();

        if ($hasPending) {
            return 'pending';
        }

        $allFailed = $payments->every(fn ($p) => $p->status === PaymentStatusEnum::FAILED->value);

        if ($allFailed) {
            return PaymentStatusEnum::FAILED->value;
        }

        return null;
    }
}
