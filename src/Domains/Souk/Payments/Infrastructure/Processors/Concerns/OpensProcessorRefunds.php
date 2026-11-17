<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Actions\CreateRefundAction;
use Kanvas\Souk\Payments\DataTransferObject\RefundPayment;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;

trait OpensProcessorRefunds
{
    protected function openRefund(Payments $payment, ?float $amount, array $context): PaymentRefund|RefundResult
    {
        try {
            $refund = new CreateRefundAction(
                RefundPayment::forProcessor($payment, $amount, $context)
            )->execute();
        } catch (ValidationException $e) {
            return new RefundResult(
                success: false,
                message: $e->getMessage(),
                transactionId: '',
            );
        }

        if ($refund->isCompleted()) {
            return new RefundResult(
                success: true,
                message: 'Refund already completed',
                transactionId: (string) ($refund->processor_refund_id ?? $payment->payment_intent_id),
                raw: $refund->metadata ?? [],
            );
        }

        return $refund;
    }
}
