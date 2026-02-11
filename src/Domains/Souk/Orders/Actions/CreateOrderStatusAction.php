<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\DataTransferObject\OrderStatus as OrderStatusData;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class CreateOrderStatusAction
{
    public function __construct(
        protected readonly OrderStatusData $data,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): OrderStatus
    {
        return DB::connection('commerce')->transaction(function () {
            $orderType = OrderTypes::where([
                'apps_id' => $this->app->getId(),
                'id' => $this->data->order_type_id,
            ])->first();

            if (! $orderType) {
                throw new ValidationException('Order type not found');
            }

            $existingSequence = OrderStatus::where([
                'order_types_id' => $orderType->getId(),
                'is_deleted' => false,
            ])->where('sequence', $this->data->sequence)->exists();

            if ($existingSequence) {
                throw new ValidationException("Sequence {$this->data->sequence} is already in use for this order type");
            }

            $status = OrderStatus::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'order_types_id' => $orderType->getId(),
                'slug' => $this->data->slug,
            ], [
                'name' => $this->data->name,
                'is_default' => $this->data->is_default ?? false,
                'is_final' => $this->data->is_final ?? false,
                'sequence' => $this->data->sequence,
            ]);

            if (! empty($this->data->transitions_from)) {
                foreach ($this->data->transitions_from as $fromSlug) {
                    $fromStatus = OrderStatus::where([
                        'apps_id' => $this->app->getId(),
                        'order_types_id' => $orderType->getId(),
                        'slug' => $fromSlug,
                    ])->first();

                    if ($fromStatus) {
                        OrderStatusTransitions::firstOrCreate([
                            'order_types_id' => $orderType->getId(),
                            'from_status_id' => $fromStatus->getId(),
                            'to_status_id' => $status->getId(),
                        ], [
                            'name' => "{$fromStatus->name} to {$status->name}",
                        ]);
                    }
                }
            }

            $orderType->update([
                'total_statuses' => OrderStatus::where('order_types_id', $orderType->getId())
                    ->where('is_deleted', false)
                    ->count(),
            ]);

            return $status;
        });
    }
}
