<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Concerns;

use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

trait RecordsRetryState
{
    protected static function recordPendingAttempt(
        Payments $payment,
        string $operation,
        array $marker,
        string $reason,
        string $error
    ): void {
        $attempts = (int) ($payment->metadata["{$operation}_attempts"] ?? 0) + 1;

        $payment->addMetadata([
            ...$marker,
            "{$operation}_attempts" => $attempts,
            "{$operation}_reason" => $reason,
            "{$operation}_last_error" => $error,
        ]);
        $payment->saveQuietly();

        $payment->addLog("payment_{$operation}_failed", [
            'order_id' => $payment->payable_id,
            'reason' => $reason,
            'error' => $error,
            'attempt' => $attempts,
        ]);
    }

    protected static function recordExhaustion(
        Payments $payment,
        string $operation,
        Throwable $exception,
        int $tries
    ): void {
        $payment->addLog("payment_{$operation}_exhausted", [
            'order_id' => $payment->payable_id,
            'reason' => $payment->metadata["{$operation}_reason"] ?? null,
            'error' => $exception->getMessage(),
            'tries' => $tries,
        ]);

        report($exception);
    }
}
