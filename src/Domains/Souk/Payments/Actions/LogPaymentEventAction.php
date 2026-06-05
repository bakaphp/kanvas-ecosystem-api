<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

class LogPaymentEventAction
{
    public function execute(
        Payments $payment,
        string $event,
        array $context = [],
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        try {
            PaymentLogs::create([
                'payments_id' => $payment->id,
                'apps_id' => $payment->apps_id,
                'companies_id' => $payment->companies_id,
                'users_id' => $payment->users_id,
                'payment_methods_id' => $payment->payment_methods_id,
                'payable_id' => $payment->payable_id,
                'payable_type' => $payment->payable_type,
                'status' => $event,
                'event_type' => $event,
                'error_code' => $errorCode,
                'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 500) : null,
                'metadata' => $context,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
