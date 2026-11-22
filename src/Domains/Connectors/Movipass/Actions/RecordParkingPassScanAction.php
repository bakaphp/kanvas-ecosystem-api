<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Souk\Orders\Actions\CalculateOrderCommissionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Throwable;

class RecordParkingPassScanAction
{
    public const string EXIT_AT = 'exit_at';
    private const string LOCK_PREFIX = 'movipass:parking-exit:';
    private const int LOCK_SECONDS = 30;
    private const int WAIT_SECONDS = 10;

    public function __construct(
        protected readonly Order $order,
        protected readonly int $useNumber,
        protected readonly int $maxUses,
        protected readonly Carbon $scannedAt,
    ) {
    }

    public function execute(): bool
    {
        $recorded = false;

        if ($this->useNumber === 1) {
            $recorded = $this->underLock(fn (): bool => $this->recordEntry());
        }

        if ($this->useNumber === $this->maxUses && $this->useNumber > 1) {
            $recorded = $this->underLock(fn (): bool => $this->recordExit()) || $recorded;
        }

        return $recorded;
    }

    private function underLock(callable $callback): bool
    {
        return Cache::lock(self::LOCK_PREFIX . $this->order->getId(), self::LOCK_SECONDS)
            ->block(self::WAIT_SECONDS, $callback);
    }

    private function recordEntry(): bool
    {
        $this->order->refresh();

        if (isset(ParkingMetadata::of($this->order)[ParkingMetadata::ENTRY_AT])) {
            return false;
        }

        if ($this->order->orderStatus?->slug !== MovipassOrderStatusEnum::CREATED->slug()) {
            return false;
        }

        $this->order->transitionToStatus($this->order->user, MovipassOrderStatusEnum::ACTIVE->value);

        if ($this->order->refresh()->orderStatus?->slug !== MovipassOrderStatusEnum::ACTIVE->slug()) {
            return false;
        }

        ParkingMetadata::put($this->order, [ParkingMetadata::ENTRY_AT => $this->scannedAt->toIso8601String()]);

        return true;
    }

    private function recordExit(): bool
    {
        $this->order->refresh();

        if (isset(ParkingMetadata::of($this->order)[self::EXIT_AT])) {
            return false;
        }

        if ($this->order->orderStatus?->slug !== MovipassOrderStatusEnum::ACTIVE->slug()) {
            return false;
        }

        $this->order->transitionToStatus($this->order->user, MovipassOrderStatusEnum::COMPLETED->value);

        if ($this->order->refresh()->orderStatus?->slug !== MovipassOrderStatusEnum::COMPLETED->slug()) {
            return false;
        }

        ParkingMetadata::put($this->order, [self::EXIT_AT => $this->scannedAt->toIso8601String()]);

        $this->warnWhenExitWasNotPaidAndConfirmed();
        new CalculateOrderCommissionAction($this->order)->execute();
        $this->sendReceipt();

        return true;
    }

    private function warnWhenExitWasNotPaidAndConfirmed(): void
    {
        $confirmed = isset(ParkingMetadata::of($this->order)[ParkingMetadata::PAYMENT_CONFIRMED_AT]);

        if (! ParkingQuotePayment::isPaid($this->order) || ! $confirmed) {
            Log::warning('Parking exit completed without a paid and confirmed quote', [
                'order_id' => $this->order->getId(),
            ]);
        }
    }

    private function sendReceipt(): void
    {
        $payment = $this->order->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->first();

        if ($payment === null) {
            Log::warning('Parking exit completed without a paid payment to receipt', [
                'order_id' => $this->order->getId(),
            ]);

            return;
        }

        try {
            new SendPaymentReceiptAction($this->order, $payment, $payment->user)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
