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
use Kanvas\Souk\Payments\Actions\SendPaymentReceiptAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class RecordParkingPassScanAction
{
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
        return $this->advance(
            MovipassOrderStatusEnum::CREATED,
            MovipassOrderStatusEnum::ACTIVE,
            ParkingMetadata::ENTRY_AT
        );
    }

    private function recordExit(): bool
    {
        if (! $this->advance(MovipassOrderStatusEnum::ACTIVE, MovipassOrderStatusEnum::COMPLETED, ParkingMetadata::EXIT_AT)) {
            return false;
        }

        $this->warnWhenExitWasNotPaidAndConfirmed();
        new CalculateOrderCommissionAction($this->order)->execute();
        $this->sendReceipt();

        return true;
    }

    private function advance(
        MovipassOrderStatusEnum $from,
        MovipassOrderStatusEnum $to,
        string $timestampKey
    ): bool {
        $this->order->refresh();

        if (isset(ParkingMetadata::of($this->order)[$timestampKey])) {
            return false;
        }

        if ($this->order->orderStatus?->slug !== $from->slug()) {
            return false;
        }

        $this->order->transitionToStatus($this->order->user, $to->value);

        if ($this->order->refresh()->orderStatus?->slug !== $to->slug()) {
            return false;
        }

        ParkingMetadata::put($this->order, [$timestampKey => $this->scannedAt->toIso8601String()]);

        return true;
    }

    private function warnWhenExitWasNotPaidAndConfirmed(): void
    {
        if (! ParkingQuotePayment::isPaid($this->order) || ! ParkingMetadata::isPaymentConfirmed($this->order)) {
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

        SendPaymentReceiptAction::trySend($this->order, $payment);
    }
}
