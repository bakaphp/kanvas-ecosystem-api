<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Kanvas\Souk\Payments\DataTransferObject\PaymentFailure;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

class LogPaymentEventAction
{
    public function execute(
        Payments $payment,
        string $event,
        array $context = [],
        ?PaymentFailure $failure = null,
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
                'error_code' => $failure?->code,
                'error_message' => $failure?->message ? mb_substr($failure->message, 0, 500) : null,
                'processor_response_code' => $failure?->processorResponseCode,
                'response_insight' => $failure?->responseInsight,
                'metadata' => $context,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
