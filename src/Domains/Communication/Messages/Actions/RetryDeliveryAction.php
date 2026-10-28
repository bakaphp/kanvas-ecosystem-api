<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Jobs\DispatchDeliveryJob;
use Kanvas\Communication\Messages\Models\Delivery;

final class RetryDeliveryAction
{
    public function __construct(private readonly Delivery $delivery)
    {
    }

    /**
     * @return bool whether the delivery was queued again. Only a failed delivery is: accepted would
     *              be a second message, and unknown may already have been one.
     */
    public function execute(): bool
    {
        if ($this->delivery->status !== DeliveryStatusEnum::FAILED || $this->delivery->to_address === null) {
            return false;
        }

        $connection = $this->delivery->connection;

        if ($connection === null) {
            return false;
        }

        $unavailable = $connection->unavailableReason();

        if ($unavailable !== null) {
            $this->delivery->forceFill(['error_code' => $unavailable])->saveOrFail();

            return false;
        }

        // A retry is a decision made now, so it carries the connection's version as it is now.
        $requeued = Delivery::query()
            ->whereKey($this->delivery->getKey())
            ->where('status', DeliveryStatusEnum::FAILED->value)
            ->update([
                'status' => DeliveryStatusEnum::PENDING->value,
                'error_code' => null,
                'error' => null,
                'events_closed_at' => null,
                'activation_version' => $connection->activation_version,
                'last_event_at' => now(),
            ]);

        if ($requeued !== 1) {
            return false;
        }

        DispatchDeliveryJob::dispatch($this->delivery->refresh());

        return true;
    }
}
