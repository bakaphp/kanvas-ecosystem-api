<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\DataTransferObject\RefundPayment;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;

class CreateRefundAction
{
    public function __construct(
        protected readonly RefundPayment $data,
    ) {
    }

    public function execute(): PaymentRefund
    {
        $payment = $this->data->payment;

        if ($payment->status !== PaymentStatusEnum::PAID->value) {
            throw new ValidationException('Can only refund paid payments');
        }

        $alreadyRefunded = $payment->getRefundedAmount();
        $remainingRefundable = (float) $payment->amount - $alreadyRefunded;

        if ($this->data->amount > $remainingRefundable) {
            throw new ValidationException(
                "Refund amount ({$this->data->amount}) exceeds refundable amount ({$remainingRefundable})"
            );
        }

        return DB::connection('commerce')->transaction(function () use ($payment, $alreadyRefunded) {
            $refund = new PaymentRefund();
            $refund->apps_id = $this->data->app->getId();
            $refund->companies_id = $this->data->company->getId();
            $refund->payments_id = $payment->id;
            $refund->users_id = $this->data->user->getId();
            $refund->amount = $this->data->amount;
            $refund->currency = $payment->currency;
            $refund->reason = $this->data->reason;
            $refund->processor_refund_id = $this->data->processorRefundId;
            $refund->status = RefundStatusEnum::PENDING->value;
            $refund->saveOrFail();

            $payment->addLog('refund_initiated', [
                'event_type' => 'refund_initiated',
                'refund_id' => $refund->id,
                'amount' => $this->data->amount,
                'reason' => $this->data->reason,
            ]);

            return $refund;
        });
    }
}
