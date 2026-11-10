<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\DhlTrackingEventEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class SyncDhlTrackingAction
{
    public const int NUMBERS_PER_CALL = 200;
    public const int STALE_AFTER_DAYS = 30;
    public const string EPOD_FIELD = 'dhl-epod';

    private array $epodAttempted = [];

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
    ) {
    }

    public function execute(): array
    {
        $summary = ['polled' => 0, 'transitioned' => 0, 'epod_stored' => 0];

        $dispatched = $this->ordersIn(OrderStatusEnum::DISPATCHED)
            ->reject(fn (Order $order): bool => $order->get(CustomFieldEnum::BOOKING_STATE->value) === BookingStateEnum::MANUAL_REVIEW->value);

        foreach ($dispatched->chunk(self::NUMBERS_PER_CALL) as $chunk) {
            $this->syncChunk($chunk->keyBy('tracking_client_id'), $summary);
        }

        foreach ($this->deliveredWithoutEpod() as $order) {
            $summary['epod_stored'] += (int) $this->storeEpod($order);
        }

        return $summary;
    }

    private function syncChunk(Collection $ordersByAwb, array &$summary): void
    {
        $response = new Client($this->company)->tracking($ordersByAwb->keys()->all());

        foreach ($response['shipments'] ?? [] as $shipment) {
            $order = $ordersByAwb->get($shipment['shipmentTrackingNumber'] ?? null);

            if ($order === null) {
                continue;
            }

            $summary['polled']++;
            $order->set(CustomFieldEnum::TRACKING->value, $shipment);
            $order->set(CustomFieldEnum::TRACKING_UPDATED_AT->value, Carbon::now()->toIso8601String());

            try {
                $summary['transitioned'] += (int) $this->applyLatestEvent($order, $shipment['events'] ?? []);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function applyLatestEvent(Order $order, array $events): bool
    {
        $latest = collect($events)->sortBy(fn (array $event): string => ($event['date'] ?? '') . ' ' . ($event['time'] ?? ''))->last();
        $status = DhlTrackingEventEnum::tryFrom((string) ($latest['typeCode'] ?? ''))?->orderStatus();

        if ($status === null) {
            return false;
        }

        $order->transitionToStatus($this->company->user, $status->value);

        if ($status !== OrderStatusEnum::DELIVERED) {
            return true;
        }

        $order->set(CustomFieldEnum::DELIVERED_AT->value, ($latest['date'] ?? '') . ' ' . ($latest['time'] ?? ''));

        if (! $order->isFulfilled()) {
            $order->fulfill();
        }

        $this->storeEpod($order);

        return true;
    }

    private function storeEpod(Order $order): bool
    {
        $this->epodAttempted[$order->getId()] = true;
        $proof = new Client($this->company)->proofOfDelivery((string) $order->tracking_client_id);
        $document = $proof['documents'][0] ?? null;

        if ($document === null) {
            return false;
        }

        app(AttachDhlDocumentAction::class)->execute(
            $order,
            $document['content'],
            "dhl-epod-{$order->tracking_client_id}.pdf",
            self::EPOD_FIELD
        );
        $order->set(CustomFieldEnum::EPOD_STORED_AT->value, Carbon::now()->toIso8601String());

        return true;
    }

    private function deliveredWithoutEpod(): Collection
    {
        return $this->ordersIn(OrderStatusEnum::DELIVERED)
            ->filter(fn (Order $order): bool => ! isset($this->epodAttempted[$order->getId()])
                && $order->get(CustomFieldEnum::EPOD_STORED_AT->value) === null);
    }

    private function ordersIn(OrderStatusEnum $status): Collection
    {
        $orderStatus = $status->forApp($this->app);

        if ($orderStatus === null) {
            return new Collection();
        }

        return Order::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('order_status_id', $orderStatus->getId())
            ->whereNotNull('tracking_client_id')
            ->where('shipped_date', '>=', Carbon::now()->subDays(self::STALE_AFTER_DAYS))
            ->get();
    }
}
