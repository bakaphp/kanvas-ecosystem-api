<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;

/**
 * The person's On/Off. Off cancels what is queued; On moves the activation version, so anything
 * queued before the Off — a delivery, a burst, a responder turn — finds a stale version and is
 * dropped rather than released.
 */
final class SetConnectionEnabledAction
{
    public function __construct(
        private readonly Connection $connection,
        private readonly bool $enabled
    ) {
    }

    public function execute(): Connection
    {
        $this->enabled ? $this->switchOn() : $this->switchOff();

        return $this->connection->refresh();
    }

    private function switchOff(): void
    {
        DB::connection('workflow')->transaction(function () {
            Connection::query()->whereKey($this->connection->getKey())->update(['enabled' => false]);

            self::skipPending([$this->connection->getId()], 'connection_disabled');
        });
    }

    /**
     * The version moves only when this statement changes a row, so pressing On twice is one
     * transition, not two — the second cancels nothing and invalidates nothing.
     */
    private function switchOn(): void
    {
        $switched = Connection::query()
            ->whereKey($this->connection->getKey())
            ->where('enabled', false)
            ->live()
            ->update([
                'enabled' => true,
                'activation_version' => DB::raw('activation_version + 1'),
            ]);

        if ($switched === 1 && $this->connection->integrationCompany !== null) {
            EnsureCommunicationSetupJob::dispatch($this->connection->app, $this->connection->integrationCompany);
        }
    }

    /**
     * @param list<int> $connectionIds
     */
    public static function skipPending(array $connectionIds, string $reason): void
    {
        Delivery::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('status', DeliveryStatusEnum::PENDING->value)
            ->update([
                'status' => DeliveryStatusEnum::SKIPPED->value,
                'error_code' => $reason,
                'events_closed_at' => now(),
            ]);
    }
}
