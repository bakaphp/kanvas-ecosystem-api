<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Observers;

use Kanvas\Souk\Payments\Actions\SyncPayablePaymentStatusAction;
use Kanvas\Souk\Payments\Models\Payments;

class PaymentObserver
{
    public function updated(Payments $payment): void
    {
        if ($payment->isDirty('status') && $payment->payable) {
            new SyncPayablePaymentStatusAction($payment->payable)->execute();
        }
    }
}
