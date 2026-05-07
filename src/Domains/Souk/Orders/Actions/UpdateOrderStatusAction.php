<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\DataTransferObject\OrderStatus as OrderStatusData;
use Kanvas\Souk\Orders\Models\OrderStatus;

class UpdateOrderStatusAction
{
    public function __construct(
        protected readonly OrderStatus $orderStatus,
        protected readonly OrderStatusData $data,
    ) {
    }

    public function execute(): OrderStatus
    {
        return DB::connection('commerce')->transaction(function () {
            if ($this->data->sequence !== null && $this->data->sequence !== $this->orderStatus->sequence) {
                $existingSequence = OrderStatus::where([
                    'order_types_id' => $this->orderStatus->order_types_id,
                    'is_deleted' => false,
                ])
                    ->where('sequence', $this->data->sequence)
                    ->where('id', '!=', $this->orderStatus->getId())
                    ->exists();

                if ($existingSequence) {
                    throw new ValidationException("Sequence {$this->data->sequence} is already in use for this order type");
                }
            }

            if ($this->data->name !== null) {
                $this->orderStatus->name = $this->data->name;
            }

            if ($this->data->slug !== null) {
                $this->orderStatus->slug = $this->data->slug;
            }

            if ($this->data->is_default !== null) {
                $this->orderStatus->is_default = $this->data->is_default;
            }

            if ($this->data->is_final !== null) {
                $this->orderStatus->is_final = $this->data->is_final;
            }

            if ($this->data->sequence !== null) {
                $this->orderStatus->sequence = $this->data->sequence;
            }

            $this->orderStatus->saveOrFail();

            return $this->orderStatus;
        });
    }
}
