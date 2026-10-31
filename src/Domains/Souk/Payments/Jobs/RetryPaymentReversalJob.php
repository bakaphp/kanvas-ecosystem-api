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
use Kanvas\Souk\Payments\Concerns\RecordsRetryState;
use Kanvas\Souk\Payments\Contracts\ReleasesHoldInterface;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use RuntimeException;
use Throwable;

final class RetryPaymentReversalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use RecordsRetryState;
    use SerializesModels;

    public const string PENDING_KEY = 'reversal_pending';

    private const int FIRST_RETRY_DELAY_SECONDS = 60;

    private const array BACKOFF_SECONDS = [300, 1800, 7200, 43200, 86400];

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

    public static function markPending(
        Payments $payment,
        float $amount,
        string $reason,
        string $error
    ): void {
        self::recordPendingAttempt(
            $payment,
            'reversal',
            [self::PENDING_KEY => true, 'reversal_amount' => $amount],
            $reason,
            $error
        );
    }

    public static function schedule(
        Apps $app,
        Payments $payment,
        float $amount,
        string $reason,
        string $error
    ): void {
        if ($payment->status !== PaymentStatusEnum::FAILED->value) {
            $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
        }

        self::markPending(
            $payment,
            $amount,
            $reason,
            $error
        );
        self::dispatchFirstRetry($app, $payment);
    }

    public static function dispatchFirstRetry(Apps $app, Payments $payment): void
    {
        self::dispatch($app, $payment)->delay(now()->addSeconds(self::FIRST_RETRY_DELAY_SECONDS));
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

        if (! $processor instanceof ReleasesHoldInterface) {
            $this->fail(new RuntimeException("Processor {$processor->name()} cannot release an authorization hold."));

            return;
        }

        $amount = (float) $payment->metadata['reversal_amount'];
        $reason = (string) $payment->metadata['reversal_reason'];

        $result = $processor->releaseHold(
            $payment,
            $payment->payable,
            $amount,
            $reason
        );

        if (! $result->success) {
            self::markPending(
                $payment,
                $amount,
                $reason,
                $result->message
            );

            throw new RuntimeException("Payment {$payment->getId()} reversal failed at gateway: {$result->message}");
        }

        $payment->addMetadata([self::PENDING_KEY => false]);
        $payment->saveQuietly();
    }

    public function failed(Throwable $exception): void
    {
        $this->overwriteAppService($this->app);

        self::recordExhaustion(
            $this->payment,
            'reversal',
            $exception,
            $this->tries
        );
    }

    private function isRetryable(Payments $payment): bool
    {
        return $payment->status === PaymentStatusEnum::FAILED->value
            && ($payment->metadata[self::PENDING_KEY] ?? false) === true;
    }
}
