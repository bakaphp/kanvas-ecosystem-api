<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class OrderStatusMutation
{
    public function create(mixed $root, array $request): OrderStatus
    {
        $app = app(Apps::class);
        $input = $request['input'];

        $orderType = OrderTypes::where([
            'apps_id' => $app->getId(),
            'id' => $input['order_type_id'],
        ])->first();

        if (! $orderType) {
            throw new ValidationException('Order type not found');
        }

        $existingSequence = OrderStatus::where([
            'order_types_id' => $orderType->getId(),
            'is_deleted' => false,
        ])->where('sequence', $input['sequence'])->exists();

        if ($existingSequence) {
            throw new ValidationException("Sequence {$input['sequence']} is already in use for this order type");
        }

        $status = OrderStatus::firstOrCreate([
            'apps_id' => $app->getId(),
            'order_types_id' => $orderType->getId(),
            'slug' => $input['slug'],
        ], [
            'name' => $input['name'],
            'is_default' => $input['is_default'] ?? false,
            'is_final' => $input['is_final'] ?? false,
            'sequence' => $input['sequence'],
        ]);

        if (! empty($input['transitions_from'])) {
            foreach ($input['transitions_from'] as $fromSlug) {
                $fromStatus = OrderStatus::where([
                    'apps_id' => $app->getId(),
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
    }

    public function update(mixed $root, array $request): OrderStatus
    {
        $app = app(Apps::class);
        $input = $request['input'];

        $status = OrderStatus::where([
            'apps_id' => $app->getId(),
            'id' => $request['id'],
        ])->first();

        if (! $status) {
            throw new ValidationException('Order status not found');
        }

        if (isset($input['sequence']) && $input['sequence'] !== $status->sequence) {
            $existingSequence = OrderStatus::where([
                'order_types_id' => $status->order_types_id,
                'is_deleted' => false,
            ])
                ->where('sequence', $input['sequence'])
                ->where('id', '!=', $status->getId())
                ->exists();

            if ($existingSequence) {
                throw new ValidationException("Sequence {$input['sequence']} is already in use for this order type");
            }
        }

        $status->update($input);

        return $status;
    }
}
