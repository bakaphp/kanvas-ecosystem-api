<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\DataTransferObject\RefundPayment;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;

class CreateRefundAction
{
    public function __construct(
        protected readonly RefundPayment $data,
    ) {
    }

    public function execute(): PaymentRefund
    {
        $payment = $this->data->payment;

        return DB::connection('commerce')->transaction(function () use ($payment) {
            Payments::query()->whereKey($payment->getId())->lockForUpdate()->first();

            $existing = $this->findByIdempotencyKey($payment);

            if ($existing?->isCompleted()) {
                $this->assertSameAmount($existing);

                return $existing;
            }

            if ($payment->status !== PaymentStatusEnum::PAID->value) {
                throw new ValidationException('Can only refund paid payments');
            }

            if ($existing !== null && $existing->status !== RefundStatusEnum::FAILED->value) {
                $this->assertSameAmount($existing);

                throw new ValidationException('A refund with this idempotency key is already in progress');
            }

            $this->assertRefundable($payment);

            if ($existing !== null) {
                return $this->retryFailed($existing);
            }

            $refund = new PaymentRefund();
            $refund->apps_id = $this->data->app->getId();
            $refund->companies_id = $this->data->company->getId();
            $refund->payments_id = $payment->id;
            $refund->users_id = $this->data->user->getId();
            $refund->amount = $this->data->amount;
            $refund->currency = $payment->currency;
            $refund->reason = $this->data->reason;
            $refund->note = $this->data->note;
            $refund->processor_refund_id = $this->data->processorRefundId;
            $refund->idempotency_key = $this->data->idempotencyKey;
            $refund->status = RefundStatusEnum::PENDING->value;
            $refund->saveOrFail();

            $payment->addLog('refund_initiated', [
                'event_type' => 'refund_initiated',
                'refund_id' => $refund->id,
                'amount' => $this->data->amount,
                'reason' => $this->data->reason,
            ], actor: $this->data->user);

            return $refund;
        });
    }

    private function findByIdempotencyKey(Payments $payment): ?PaymentRefund
    {
        if ($this->data->idempotencyKey === null) {
            return null;
        }

        return PaymentRefund::query()
            ->where('payments_id', $payment->getId())
            ->where('idempotency_key', $this->data->idempotencyKey)
            ->first();
    }

    private function assertRefundable(Payments $payment): void
    {
        $remainingRefundable = $payment->getRefundableAmount();

        if ($this->data->amount <= 0 || $this->data->amount > $remainingRefundable) {
            throw new ValidationException(
                "Refund amount ({$this->data->amount}) exceeds refundable amount ({$remainingRefundable})"
            );
        }
    }

    private function retryFailed(PaymentRefund $refund): PaymentRefund
    {
        $this->assertSameAmount($refund);

        $refund->status = RefundStatusEnum::PENDING->value;
        $refund->metadata = array_merge($refund->metadata ?? [], ['attempt' => $refund->attempt() + 1]);
        $refund->saveOrFail();

        return $refund;
    }

    private function assertSameAmount(PaymentRefund $refund): void
    {
        if (round((float) $refund->amount, 2) !== round($this->data->amount, 2)) {
            throw new ValidationException('Idempotency key was already used for a refund of a different amount');
        }
    }
}
