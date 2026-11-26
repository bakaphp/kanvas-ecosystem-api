<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\RecordParkingPassScanAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Support\WalkInOrder;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Souk\Orders\Actions\CancelOrderAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class ExpireUnusedParkingVisitsAction
{
    public const int STUCK_EXIT_MINUTES = 3;

    private array $report = ['expired' => 0, 'exits_completed' => 0, 'confirmations_attempted' => 0, 'failed' => 0];

    public function __construct(
        protected readonly Apps $app,
    ) {
    }

    public function execute(): array
    {
        foreach ($this->openOrders(MovipassOrderStatusEnum::CREATED) as $order) {
            $this->guarded(fn (): bool => $this->expireIfUnused($order), 'expired');
        }

        foreach ($this->openOrders(MovipassOrderStatusEnum::ACTIVE) as $order) {
            $this->guarded(fn (): bool => $this->completeStuckExit($order), 'exits_completed');
            $this->guarded(
                fn (): bool => new DispatchParkingPaymentConfirmationAction($order->refresh())->execute(),
                'confirmations_attempted'
            );
        }

        return $this->report;
    }

    private function guarded(callable $step, string $counter): void
    {
        try {
            if ($step()) {
                $this->report[$counter]++;
            }
        } catch (Throwable $e) {
            $this->report['failed']++;
            report($e);
        }
    }

    private function openOrders(MovipassOrderStatusEnum $status): LazyCollection
    {
        return Order::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('resources_type', new Event()->getMorphClass())
            ->whereNotIn('status', OrderStatusEnum::closedValues())
            ->whereHas('orderStatus', fn ($query) => $query->where('slug', $status->slug()))
            ->lazyById()
            ->filter(fn (Order $order): bool => WalkInOrder::accessOf($order) !== null);
    }

    private function expireIfUnused(Order $order): bool
    {
        $access = WalkInOrder::accessOf($order);

        if ($access === null || ParkingMetadata::hasEntered($order) || $order->payments()->exists()) {
            return false;
        }

        $booking = EventVersion::query()->where('event_id', $order->resources_id)->live()->first();

        if ($booking === null) {
            return Event::find($order->resources_id)?->isCancelled() === true && $this->cancelOrderOnly($order);
        }

        if (
            $booking->created_at->addMinutes($access->expireUnusedMinutes)->isFuture()
            || $this->hasAdmittedScan($booking)
        ) {
            return false;
        }

        ParticipantPass::query()
            ->where('event_version_id', $booking->getId())
            ->where('use_count', 0)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);

        if ($this->hasUsedPass($booking)) {
            return false;
        }

        new CancelEventAction($booking)->withoutNotifications()->execute();

        return true;
    }

    private function cancelOrderOnly(Order $order): bool
    {
        new CancelOrderAction($order, $order->user)->execute();

        return $order->refresh()->orderStatus?->slug === MovipassOrderStatusEnum::CANCELLED->slug();
    }

    private function hasAdmittedScan(EventVersion $booking): bool
    {
        return $this->hasUsedPass($booking)
            || ParticipantPassScan::query()
                ->where('event_version_id', $booking->getId())
                ->where('result', PassScanResultEnum::ADMITTED->value)
                ->exists();
    }

    private function hasUsedPass(EventVersion $booking): bool
    {
        return ParticipantPass::query()
            ->where('event_version_id', $booking->getId())
            ->where('use_count', '>', 0)
            ->exists();
    }

    private function completeStuckExit(Order $order): bool
    {
        if (isset(ParkingMetadata::of($order)[ParkingMetadata::EXIT_AT])) {
            return false;
        }

        $pass = ParticipantPass::query()
            ->whereIn('event_version_id', EventVersion::query()->where('event_id', $order->resources_id)->select('id'))
            ->where('max_uses', '>', 1)
            ->whereColumn('use_count', '>=', 'max_uses')
            ->first();

        if ($pass === null) {
            return false;
        }

        $exitScan = ParticipantPassScan::query()
            ->where('participant_passes_id', $pass->getId())
            ->where('result', PassScanResultEnum::ADMITTED->value)
            ->where('use_number', $pass->max_uses)
            ->first();

        if ($exitScan === null || $exitScan->scanned_at->addMinutes(self::STUCK_EXIT_MINUTES)->isFuture()) {
            return false;
        }

        return new RecordParkingPassScanAction(
            order: $order,
            useNumber: (int) $pass->max_uses,
            maxUses: (int) $pass->max_uses,
            scannedAt: $exitScan->scanned_at,
        )->execute();
    }
}
