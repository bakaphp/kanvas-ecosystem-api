<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Repositories;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;

class OrderRepository
{
    public function __construct(
        protected Order $order
    ) {
    }

    public function getStatus(
        string $statusSlug,
    ): ?object {
        $status = $this->order->orderType?->statuses()
                    ->where('slug', $statusSlug)->first();
        return $status;
    }

    public function getPipeline(): array
    {
        $orderType = $this->order->orderType;

        if (! $orderType) {
            return [
                'stages' => [],
                'current_status' => null,
                'available_transitions' => collect(),
            ];
        }

        $statuses = OrderStatus::where('order_types_id', $orderType->id)
            ->where('is_deleted', false)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $currentStatus = $this->order->orderStatus;
        $currentSequence = $currentStatus?->sequence ?? -1;

        $stages = $statuses->map(function (OrderStatus $status) use ($currentStatus, $currentSequence) {
            return [
                'status' => $status,
                'is_current' => $currentStatus && $status->id === $currentStatus->id,
                'is_completed' => $status->sequence < $currentSequence,
                'sequence' => $status->sequence,
            ];
        })->toArray();

        $availableTransitions = $currentStatus
            ? $currentStatus->fromTransitions()->with('toStatus')->get()->pluck('toStatus')
            : collect();

        return [
            'stages' => $stages,
            'current_status' => $currentStatus,
            'available_transitions' => $availableTransitions,
        ];
    }
}
