<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Parking\ParkingOperatorResolver;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

final class ConfirmParkingPaymentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly Apps $app,
        public readonly Order $order,
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->order->getId();
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 7200];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $order = $this->order->fresh();

        if ($order === null) {
            return;
        }

        if (ParkingMetadata::isPaymentConfirmed($order) || ! ParkingQuotePayment::isPaid($order)) {
            return;
        }

        $reference = (string) ParkingMetadata::of($order)[ParkingMetadata::QUOTE]['reference'];

        ParkingMetadata::put($order, [
            ParkingMetadata::CONFIRMATION_ATTEMPTED_AT => Carbon::now()->toIso8601String(),
        ]);

        if (! ParkingOperatorResolver::forOrder($order)->confirmPayment($order, $reference)) {
            $this->release($this->nextDelay());

            return;
        }

        ParkingMetadata::put($order, [
            ParkingMetadata::PAYMENT_CONFIRMED_AT => Carbon::now()->toIso8601String(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->overwriteAppService($this->app);

        report($exception);
    }

    private function nextDelay(): int
    {
        $delays = $this->backoff();

        return $delays[min($this->attempts(), count($delays)) - 1];
    }
}
