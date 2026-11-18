<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideAssistanceStatusMap;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

/**
 * Makes the roadside order type match RoadsideAssistanceStatusMap, however many times it runs.
 *
 * Not CreateOrderStatusesAction: its firstOrCreate goes through the soft-delete scope, so a deleted
 * status or transition is created a second time, and an existing status never picks up a changed
 * is_default / is_final / sequence, nor loses a transition the map dropped. Rows are restored and
 * soft-deleted here, never hard-deleted, because order history points at their ids.
 */
class SyncRoadsideAssistanceStatusesAction
{
    private array $summary = [
        'statuses_created' => 0,
        'statuses_updated' => 0,
        'statuses_restored' => 0,
        'transitions_created' => 0,
        'transitions_restored' => 0,
        'transitions_removed' => 0,
        'duplicate_statuses' => [],
    ];

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return array<string, int|array<int, string>>
     */
    public function execute(): array
    {
        DB::connection('commerce')->transaction(function () {
            $definition = RoadsideAssistanceStatusMap::definition();
            $orderType = $this->syncOrderType();

            $statuses = [];
            $sequence = 1;
            foreach ($definition as $key => $config) {
                $statuses[$key] = $this->syncStatus($orderType, $key, $config, $sequence++);
            }

            $this->syncTransitions($orderType, $definition, $statuses);

            $orderType->updateQuietly(['total_statuses' => count($statuses)]);
        });

        return $this->summary;
    }

    private function syncOrderType(): OrderTypes
    {
        $orderType = OrderTypes::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value)
            ->orderBy('id')
            ->first();

        if ($orderType === null) {
            return OrderTypes::create([
                'apps_id' => $this->app->getId(),
                'companies_id' => 0,
                'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
                'is_default' => false,
            ]);
        }

        if ((int) $orderType->is_deleted !== 0) {
            $orderType->updateQuietly(['is_deleted' => 0]);
        }

        return $orderType;
    }

    private function syncStatus(OrderTypes $orderType, string $key, array $config, int $sequence): OrderStatus
    {
        $slug = Str::slug($key);
        $attributes = [
            'name' => $config['name'],
            'is_default' => (int) ($config['is_default'] ?? false),
            'is_final' => (int) ($config['is_final'] ?? false),
            'sequence' => $sequence,
        ];

        $matches = OrderStatus::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('order_types_id', $orderType->getId())
            ->where('slug', $slug)
            ->orderBy('is_deleted')
            ->orderBy('id')
            ->get();

        // Earlier runs could duplicate a slug. Orders point at those ids, so they are reported for a
        // manual merge instead of being deleted here.
        if ($matches->where('is_deleted', 0)->count() > 1) {
            $this->summary['duplicate_statuses'][] = $slug;
        }

        $status = $matches->first();

        if ($status === null) {
            $this->summary['statuses_created']++;

            return OrderStatus::create([
                ...$attributes,
                'apps_id' => $this->app->getId(),
                'companies_id' => 0,
                'order_types_id' => $orderType->getId(),
                'slug' => $slug,
            ]);
        }

        if ((int) $status->is_deleted !== 0) {
            $attributes['is_deleted'] = 0;
            $this->summary['statuses_restored']++;
        }

        $status->fill($attributes);

        if ($status->isDirty()) {
            if (! isset($attributes['is_deleted'])) {
                $this->summary['statuses_updated']++;
            }
            $status->saveQuietly();
        }

        return $status;
    }

    /**
     * @param array<string, OrderStatus> $statuses
     */
    private function syncTransitions(OrderTypes $orderType, array $definition, array $statuses): void
    {
        $wanted = [];
        foreach ($definition as $from => $config) {
            foreach ($config['transitions'] ?? [] as $to) {
                $wanted[$statuses[$from]->getId() . ':' . $statuses[$to]->getId()] = [$statuses[$from], $statuses[$to]];
            }
        }

        $existing = OrderStatusTransitions::withTrashed()
            ->where('order_types_id', $orderType->getId())
            ->orderBy('is_deleted')
            ->orderBy('id')
            ->get();

        $kept = [];
        foreach ($existing as $transition) {
            $pair = $transition->from_status_id . ':' . $transition->to_status_id;
            $isActive = (int) $transition->is_deleted === 0;

            if (! isset($wanted[$pair]) || isset($kept[$pair])) {
                if ($isActive) {
                    $transition->updateQuietly(['is_deleted' => 1]);
                    $this->summary['transitions_removed']++;
                }

                continue;
            }

            $kept[$pair] = true;

            if (! $isActive) {
                $transition->updateQuietly(['is_deleted' => 0]);
                $this->summary['transitions_restored']++;
            }
        }

        foreach ($wanted as $pair => [$from, $to]) {
            if (isset($kept[$pair])) {
                continue;
            }

            OrderStatusTransitions::create([
                'order_types_id' => $orderType->getId(),
                'from_status_id' => $from->getId(),
                'to_status_id' => $to->getId(),
                'name' => $from->name . ' to ' . $to->name,
                'companies_id' => 0,
            ]);
            $this->summary['transitions_created']++;
        }
    }
}
