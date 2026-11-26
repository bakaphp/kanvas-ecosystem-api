<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Payments\Actions\SendPaymentReceiptAction;
use Kanvas\Souk\Payments\Concerns\RecordsRetryState;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use RuntimeException;
use Throwable;

final class RetryPaymentCaptureJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use RecordsRetryState;
    use SerializesModels;

    public const string PENDING_KEY = 'capture_pending';

    public const string NEEDS_REVIEW_KEY = 'capture_needs_review';

    private const int FIRST_RETRY_DELAY_SECONDS = 60;

    private const array BACKOFF_SECONDS = [300, 1800, 7200, 43200];

    public int $tries = 6;

    public int $uniqueFor = 172800;

    public function __construct(
        public readonly Apps $app,
        public readonly Payments $payment,
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->payment->getId();
    }

    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public static function schedule(
        Apps $app,
        Payments $payment,
        string $reason,
        string $error
    ): void {
        self::recordPendingAttempt(
            $payment,
            'capture',
            [self::PENDING_KEY => true],
            $reason,
            $error
        );

        self::dispatch($app, $payment)->delay(now()->addSeconds(self::FIRST_RETRY_DELAY_SECONDS));
    }

    public static function flagForReview(Payments $payment, string $reason, string $error): void
    {
        self::markNeedsReview($payment);

        $payment->addLog('payment_capture_needs_review', [
            'order_id' => $payment->payable_id,
            'reason' => $reason,
            'error' => $error,
        ]);

        report(new RuntimeException("Payment {$payment->getId()} capture needs review: {$error}"));
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $payment = $this->payment->fresh();

        if (! $this->isRetryable($payment)) {
            return;
        }

        $processor = ProcessorFactory::make(
            $payment->paymentMethod?->processor ?? $payment->processor,
            $this->app,
            $payment->company
        );

        $result = $processor->capture($payment, $payment->payable);

        if ($result->skipped) {
            return;
        }

        if ($result->success) {
            SendPaymentReceiptAction::trySend($payment->payable, $payment);

            return;
        }

        if (! $result->retryable) {
            self::flagForReview(
                $payment,
                (string) $payment->metadata['capture_reason'],
                $result->message
            );

            return;
        }

        self::recordPendingAttempt(
            $payment,
            'capture',
            [self::PENDING_KEY => true],
            (string) $payment->metadata['capture_reason'],
            $result->message
        );

        throw new RuntimeException("Payment {$payment->getId()} capture failed at gateway: {$result->message}");
    }

    public function failed(Throwable $exception): void
    {
        $this->overwriteAppService($this->app);

        self::recordExhaustion(
            $this->payment,
            'capture',
            $exception,
            $this->tries
        );

        $payment = $this->payment->fresh();

        if ($payment->status === PaymentStatusEnum::AUTHORIZED->value) {
            self::markNeedsReview($payment);
        }
    }

    private static function markNeedsReview(Payments $payment): void
    {
        $payment->addMetadata([
            self::PENDING_KEY => false,
            self::NEEDS_REVIEW_KEY => true,
        ]);
        $payment->saveQuietly();
    }

    private function isRetryable(Payments $payment): bool
    {
        return $payment->status === PaymentStatusEnum::AUTHORIZED->value
            && ($payment->metadata[self::PENDING_KEY] ?? false) === true
            && ($payment->metadata[self::NEEDS_REVIEW_KEY] ?? false) !== true;
    }
}
