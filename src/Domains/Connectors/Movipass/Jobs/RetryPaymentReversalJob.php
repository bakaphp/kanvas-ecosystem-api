<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\ReversePaymentAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use RuntimeException;
use Throwable;

final class RetryPaymentReversalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string PENDING_KEY = 'reversal_pending';

    public int $tries = 6;

    // tests only: lets handle() run against a mocked gateway
    public ?PortalPaymentProcessor $paymentProcessor = null;

    public function __construct(
        public readonly Apps $app,
        public readonly Payments $payment,
        public readonly Order $order,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 43200];
    }

    /**
     * The gateway still holds the authorization, so the row must keep saying AUTHORIZED
     * (not FAILED) until a reversal actually goes through.
     */
    public static function markPending(Payments $payment, string $reason, string $error): void
    {
        $attempts = (int) ($payment->metadata['reversal_attempts'] ?? 0) + 1;

        $payment->addMetadata([
            self::PENDING_KEY => true,
            'reversal_attempts' => $attempts,
            'reversal_reason' => $reason,
            'reversal_last_error' => $error,
        ]);
        $payment->status = PaymentStatusEnum::AUTHORIZED->value;
        $payment->saveQuietly();

        $payment->addLog('payment_reversal_failed', [
            'order_id' => $payment->payable_id,
            'reason' => $reason,
            'error' => $error,
            'attempt' => $attempts,
        ]);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $payment = $this->payment->fresh();

        if (in_array($payment->status, [PaymentStatusEnum::REVERSED->value, PaymentStatusEnum::PAID->value], true)) {
            return;
        }

        $result = new ReversePaymentAction($this->app, $payment, $this->order, $this->paymentProcessor)
            ->execute($this->reason);

        if ($result['status'] === 'error') {
            self::markPending($payment, $this->reason, (string) $result['message']);

            throw new RuntimeException("Payment {$payment->getId()} reversal failed at gateway: {$result['message']}");
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->overwriteAppService($this->app);

        $this->payment->addLog('payment_reversal_exhausted', [
            'order_id' => $this->order->getId(),
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
            'tries' => $this->tries,
        ]);

        report($exception);
    }
}
