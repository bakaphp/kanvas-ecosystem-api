<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Observers;

use Kanvas\Souk\Payments\Actions\SyncPayablePaymentStatusAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\WorkflowEnum;

class PaymentObserver
{
    public function updated(Payments $payment): void
    {
        if (! $payment->isDirty('status') || ! $payment->payable) {
            return;
        }

        new SyncPayablePaymentStatusAction($payment->payable)->execute();

        if ($payment->status === PaymentStatusEnum::AUTHORIZED->value) {
            $this->fireAuthorizedWorkflow($payment);
        }
    }

    private function fireAuthorizedWorkflow(Payments $payment): void
    {
        $payable = $payment->payable;

        if (! method_exists($payable, 'hasWorkflow') || ! $payable->hasWorkflow()) {
            return;
        }

        $payable->fireWorkflow(
            event: WorkflowEnum::AFTER_PAYMENT_AUTHORIZED->value,
            async: true,
            params: [
                'app' => $payable->app,
                'company' => $payable->company,
                'payment_id' => $payment->getId(),
            ]
        );
    }
}
