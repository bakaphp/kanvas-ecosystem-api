<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;

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

        $payments = $this->payable->payments()
            ->with([
                'refunds' => fn ($q) => $q->where('status', RefundStatusEnum::COMPLETED->value),
            ])
            ->get();

        if ($payments->isEmpty()) {
            return;
        }

        $newStatus = $this->computeStatus($payments);

        if ($newStatus && $this->payable->payment_status !== $newStatus) {
            $this->payable->updateQuietly(['payment_status' => $newStatus]);
        }
    }

    protected function computeStatus(Collection $payments): ?string
    {
        $totalAmount = (float) ($this->payable->total_net_amount ?? 0);
        $paidPayments = $payments->where('status', PaymentStatusEnum::PAID->value);
        $paidAmount = (float) $paidPayments->sum('amount');

        $refundedAmount = (float) $paidPayments->sum(
            fn ($payment) => $payment->refunds->sum('amount')
        );

        $netPaid = $paidAmount - $refundedAmount;

        if ($paidAmount > 0 && $refundedAmount >= $paidAmount) {
            return PaymentStatusEnum::REVERSED->value;
        }

        if ($netPaid > 0 && $netPaid >= $totalAmount) {
            return PaymentStatusEnum::PAID->value;
        }

        $hasPending = $payments->whereIn('status', [
            PaymentStatusEnum::PENDING->value,
            PaymentStatusEnum::PROCESSING->value,
            PaymentStatusEnum::WAITING_DEVICE_DATA->value,
            PaymentStatusEnum::PENDING_AUTHORIZATION->value,
        ])->isNotEmpty();

        if ($hasPending) {
            return PaymentStatusEnum::PENDING->value;
        }

        $allFailed = $payments->every(fn ($p) => $p->status === PaymentStatusEnum::FAILED->value);

        if ($allFailed) {
            return PaymentStatusEnum::FAILED->value;
        }

        return null;
    }
}
