<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Ledger\DataTransferObject\EventAnalyticsBucket;
use Kanvas\NervousSystem\Ledger\DataTransferObject\EventAnalyticsQuery;
use Kanvas\NervousSystem\Ledger\Enums\EventGroupByEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Generic aggregate layer over the ledger (`nervous_system_events`): counts,
 * distinct-entity counts, group-by, and payload-key presence — reusable for any
 * event type. Domain meaning (what a payload key means, entity→company joins)
 * stays in the consumer. Tenant scoping is applied on every query here.
 */
class EventAnalyticsService
{
    public function count(EventAnalyticsQuery $query): int
    {
        return $this->baseQuery($query)->count();
    }

    public function countDistinctEntities(EventAnalyticsQuery $query): int
    {
        return $this->baseQuery($query)->distinct()->count('source_entity_id');
    }

    /**
     * @return Collection<int, EventAnalyticsBucket>
     */
    public function groupBy(EventAnalyticsQuery $query, EventGroupByEnum $by): Collection
    {
        $rows = $this->baseQuery($query)
            ->select(DB::raw($by->value . ' as bucket_key'))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT source_entity_id) as distinct_entities')
            ->groupBy('bucket_key')
            ->orderBy('bucket_key')
            ->get();

        return collect($rows)->map(fn ($row) => new EventAnalyticsBucket(
            key: (string) $row->bucket_key,
            count: (int) $row->total,
            distinctEntities: (int) $row->distinct_entities,
        ))->values();
    }

    /**
     * For each JSON path, how many matching events carry it. One bucket per path
     * (key = the path) — the shape the dashboard donuts read.
     *
     * @param string[] $jsonPaths e.g. ['changes.current_employer', 'changes.title']
     *
     * @return Collection<int, EventAnalyticsBucket>
     */
    public function countByPayloadKeys(EventAnalyticsQuery $query, array $jsonPaths): Collection
    {
        return collect($jsonPaths)->map(function (string $path) use ($query) {
            $scoped = $this->baseQuery($query)->whereRaw('JSON_EXTRACT(payload, ?) IS NOT NULL', ['$.' . $path]);

            return new EventAnalyticsBucket(
                key: $path,
                count: (clone $scoped)->count(),
                distinctEntities: (clone $scoped)->distinct()->count('source_entity_id'),
            );
        })->values();
    }

    private function baseQuery(EventAnalyticsQuery $query): Builder
    {
        $builder = Event::query()->fromApp($query->app);

        if ($query->company !== null) {
            $builder->fromCompany($query->company);
        }

        if ($query->eventTypes !== []) {
            $builder->whereIn('event_type', $query->eventTypes);
        }

        if ($query->sourceDomain !== null) {
            $builder->where('source_domain', $query->sourceDomain);
        }

        if ($query->sourceEntityType !== null) {
            $builder->where('source_entity_type', $query->sourceEntityType);
        }

        if ($query->actorType !== null) {
            $builder->where('actor_type', $query->actorType);
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }

        if ($query->from !== null) {
            $builder->where('occurred_at', '>=', $query->from);
        }

        if ($query->to !== null) {
            $builder->where('occurred_at', '<=', $query->to);
        }

        foreach ($query->payloadHasKeys as $path) {
            $builder->whereRaw('JSON_EXTRACT(payload, ?) IS NOT NULL', ['$.' . $path]);
        }

        return $builder;
    }
}
