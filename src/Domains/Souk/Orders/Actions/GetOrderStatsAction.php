<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Enums\DateGroupByEnum;
use Kanvas\Souk\Orders\Enums\OrderStatsExcludeModeEnum;
use Kanvas\Souk\Orders\Helpers\DateGroupingHelper;
use Kanvas\Souk\Orders\Helpers\DateHelper;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;

class GetOrderStatsAction
{
    protected ?Collection $productVariantIds = null;

    public function __construct(
        protected Apps $app,
        protected array $initialStates,
        protected array $finalStates,
        protected array $currentCountStates = [],
        protected array $productTypeSlugs = [],
        protected array $orderTypeNames = [],
        protected ?int $productId = null,
        protected ?int $variantId = null,
        protected array $providerCompanyIds = [],
        protected array $providers = [],
        protected ?string $userEmail = null,
        protected array $excludeStates = [],
        protected OrderStatsExcludeModeEnum $excludeMode = OrderStatsExcludeModeEnum::CURRENT,
    ) {
        if ($this->variantId) {
            $this->productVariantIds = collect([$this->variantId]);

            return;
        }

        if ($this->productId) {
            $this->productVariantIds = DB::connection('inventory')
                ->table('products_variants')
                ->where('products_id', $this->productId)
                ->pluck('id');
        }
    }

    public function execute(
        ?string $date = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $baseDate = null,
        string $timezone = 'UTC',
        string $groupBy = 'day'
    ): array {
        if ($date && (! $startDate || ! $endDate)) {
            $start = Carbon::parse($date, $timezone)->startOfDay()->timezone('UTC');
            $end = Carbon::parse($date, $timezone)->endOfDay()->timezone('UTC');
        } else {
            $start = $startDate ? Carbon::parse($startDate, $timezone)->startOfDay()->timezone('UTC') : now()->startOfDay()->timezone('UTC');
            $end = $endDate ? Carbon::parse($endDate, $timezone)->endOfDay()->timezone('UTC') : now()->endOfDay()->timezone('UTC');
        }

        $currentCount = $this->getCurrentCount($baseDate, $timezone);
        $dailyTurnover = $this->getDailyTurnover($start, $end, $timezone);
        $ordersInPeriod = $this->getOrdersInPeriod($start, $end, $timezone);

        $groupByEnum = DateGroupByEnum::from($groupBy);

        if ($groupByEnum !== DateGroupByEnum::DAY) {
            $ordersInPeriod = DateGroupingHelper::groupOrdersInPeriod($ordersInPeriod, $groupByEnum, $timezone);
            $dailyTurnover = DateGroupingHelper::groupTurnoverData($dailyTurnover, $groupByEnum, $timezone);
        }

        return [
            'period' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ],
            'ordersInPeriod' => $ordersInPeriod,
            'currentCount' => $currentCount,
            'dailyTurnover' => $dailyTurnover,
            'averageRotation' => $this->getAverageRotation($start, $end),
            'orderRotationAvg' => $ordersInPeriod['orderAvg'] > 0 ? ($dailyTurnover['totalExits'] / $ordersInPeriod['orderAvg']) : 0,
            'groupBy' => $groupBy,
            'byProvider' => $this->getByProvider($start, $end),
        ];
    }

    /**
     * Get average time for order from initial state to final state
     */
    private function getAverageRotation($start, $end): array
    {
        $connection = (new OrderTransitionHistory())->getConnectionName();

        $initialSubQuery = OrderTransitionHistory::query()
            ->selectRaw('order_id, MIN(changed_at) as initial_date')
            ->whereBetween('changed_at', [$start, $end])
            ->where('apps_id', $this->app->id)
            ->when(! empty($this->orderTypeNames), function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('orderType', function ($typeQuery) {
                        $typeQuery->whereIn('name', $this->orderTypeNames);
                    });
                });
            })
            ->when($this->productVariantIds, function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('items', function ($iq) {
                        $iq->whereIn('variant_id', $this->productVariantIds);
                    });
                });
            })
            ->when(! empty($this->providerCompanyIds), function ($query) {
                $db = config('database.connections.commerce.database', 'commerce');
                $ids = implode(',', array_map('intval', $this->providerCompanyIds));
                $query->whereRaw("order_transitions_history.order_id IN (SELECT order_id FROM {$db}.order_providers WHERE company_id IN ({$ids}))");
            })
            ->when($this->userEmail, function ($query) {
                $query->whereHas('order', fn ($q) => $q->where('orders.user_email', 'LIKE', $this->userEmail));
            })
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->initialStates);
            })
            ->groupBy('order_id')
            ->getQuery();

        $finalSubQuery = OrderTransitionHistory::query()
            ->selectRaw('order_id, MAX(changed_at) as final_date')
            ->whereBetween('changed_at', [$start, $end])
            ->where('apps_id', $this->app->id)
            ->when(! empty($this->orderTypeNames), function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('orderType', function ($typeQuery) {
                        $typeQuery->whereIn('name', $this->orderTypeNames);
                    });
                });
            })
            ->when($this->productVariantIds, function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('items', function ($iq) {
                        $iq->whereIn('variant_id', $this->productVariantIds);
                    });
                });
            })
            ->when(! empty($this->providerCompanyIds), function ($query) {
                $db = config('database.connections.commerce.database', 'commerce');
                $ids = implode(',', array_map('intval', $this->providerCompanyIds));
                $query->whereRaw("order_transitions_history.order_id IN (SELECT order_id FROM {$db}.order_providers WHERE company_id IN ({$ids}))");
            })
            ->when($this->userEmail, function ($query) {
                $query->whereHas('order', fn ($q) => $q->where('orders.user_email', 'LIKE', $this->userEmail));
            })
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->finalStates);
            })
            ->groupBy('order_id')
            ->getQuery();

        $rotationQuery = DB::connection($connection)->table(DB::raw("({$initialSubQuery->toSql()}) as initial"))
            ->mergeBindings($initialSubQuery)
            ->join(DB::raw("({$finalSubQuery->toSql()}) as final"), 'initial.order_id', '=', 'final.order_id')
            ->mergeBindings($finalSubQuery)
            ->selectRaw('initial.order_id, initial.initial_date, final.final_date, TIMESTAMPDIFF(MINUTE, initial.initial_date, final.final_date) as diff_minutes')
            ->get();

        return [
            'orders' => $rotationQuery->map(fn ($item) => [
                'orderId' => $item->order_id,
                'initialDate' => $item->initial_date,
                'finalDate' => $item->final_date,
                'time' => $item->diff_minutes,
            ]),
            'averageTime' => $rotationQuery->avg('diff_minutes') ?? 0,
        ];
    }

    /**
     * Get orders in period grouped by date and state.
     *
     * The stored `ended_at` is not trustworthy — a superseded row that kept a NULL `ended_at`
     * reads as "still current" forever, so an order that was already dispatched went on being
     * counted in its old state every later day. Each state's end is derived from the next
     * transition instead, which is self-consistent no matter how the row was written.
     */
    private function getOrdersInPeriod(Carbon $start, Carbon $end, string $timezone = 'UTC'): array
    {
        $daysInRange = collect(DateHelper::generateDateList($start, $end, $timezone))
            ->map(fn (string $date) => trim($date, "'"));

        if ($daysInRange->isEmpty()) {
            return [
                'orderAvg' => 0,
                'maxOrdersDate' => ['date' => null, 'count' => 0],
                'minOrdersDate' => ['date' => null, 'count' => 0],
                'data' => [],
            ];
        }

        // Each day gets its own UTC cutoff so the boundary follows the caller's timezone
        // (and its DST shifts) instead of cutting at UTC midnight.
        $cutoffs = $daysInRange->mapWithKeys(fn (string $date) => [
            $date => Carbon::parse($date, $timezone)->endOfDay()->timezone('UTC')->format('Y-m-d H:i:s'),
        ]);

        $bindings = [$this->app->getId(), $cutoffs->last()];
        $filters = '';

        // Raw SQL, so the Order soft-delete scope does not apply. Unconditional:
        // nesting this in an optional filter block would disable it silently.
        $filters .= ' AND EXISTS (
            SELECT 1 FROM orders
            WHERE orders.id = history.order_id
              AND orders.is_deleted = 0
        )';

        if (! empty($this->orderTypeNames)) {
            $filters .= ' AND EXISTS (
                SELECT 1 FROM orders
                INNER JOIN order_types ON order_types.id = orders.order_types_id
                WHERE orders.id = history.order_id
                  AND order_types.name IN (' . $this->bindList($this->orderTypeNames, $bindings) . ')
            )';
        }

        if ($this->productVariantIds && $this->productVariantIds->isNotEmpty()) {
            $filters .= ' AND EXISTS (
                SELECT 1 FROM order_items
                WHERE order_items.order_id = history.order_id
                  AND order_items.is_deleted = 0
                  AND order_items.variant_id IN (' . $this->bindList($this->productVariantIds->all(), $bindings) . ')
            )';
        }

        if (! empty($this->providerCompanyIds)) {
            $db = config('database.connections.commerce.database', 'commerce');
            $filters .= " AND EXISTS (
                SELECT 1 FROM {$db}.order_providers
                WHERE order_providers.order_id = history.order_id
                  AND order_providers.company_id IN (" . $this->bindList($this->providerCompanyIds, $bindings) . ')
            )';
        }

        if ($this->userEmail) {
            $bindings[] = $this->userEmail;
            $filters .= ' AND EXISTS (
                SELECT 1 FROM orders WHERE orders.id = history.order_id AND orders.user_email LIKE ?
            )';
        }

        $dateRows = $cutoffs->map(function (string $cutoff, string $date) use (&$bindings) {
            $bindings[] = $date;
            $bindings[] = $cutoff;

            return 'SELECT ? AS report_date, ? AS cutoff_utc';
        })->implode(' UNION ALL ');

        $stateFilter = '';
        if (! empty($this->currentCountStates)) {
            $stateFilter = ' WHERE order_statuses.slug IN (' . $this->bindList($this->currentCountStates, $bindings) . ')';
        }

        $sql = "
            WITH state_intervals AS (
                SELECT
                    history.order_id,
                    history.to_status_id,
                    history.changed_at,
                    LEAD(history.changed_at) OVER (
                        PARTITION BY history.order_id
                        ORDER BY history.changed_at, history.id
                    ) AS superseded_at
                FROM order_transitions_history AS history
                WHERE history.apps_id = ?
                  AND history.is_deleted = 0
                  AND history.changed_at <= CAST(? AS DATETIME)
                  {$filters}
            )
            SELECT
                order_statuses.slug AS state,
                date_range.report_date AS date,
                COUNT(DISTINCT state_intervals.order_id) AS count
            FROM ({$dateRows}) AS date_range
            INNER JOIN state_intervals
                ON state_intervals.changed_at <= CAST(date_range.cutoff_utc AS DATETIME)
               AND (
                    state_intervals.superseded_at IS NULL
                    OR state_intervals.superseded_at > CAST(date_range.cutoff_utc AS DATETIME)
               )
            INNER JOIN order_statuses ON order_statuses.id = state_intervals.to_status_id
            {$stateFilter}
            GROUP BY date_range.report_date, order_statuses.slug
            ORDER BY date_range.report_date
        ";

        $groupedResults = collect(DB::connection('commerce')->select($sql, $bindings))
            ->groupBy('date');

        $byDates = $daysInRange->map(function (string $date) use ($groupedResults) {
            $group = $groupedResults->get($date, collect());

            return [
                'date' => $date,
                'count' => (int) $group->sum('count'),
                'states' => $group->map(fn ($item) => [
                    'state' => $item->state ?? 'Unknown',
                    'count' => (int) $item->count,
                ])->values()->toArray(),
            ];
        });

        $totalEntries = $byDates->sum(fn (array $entry) => $entry['count']);

        $maxOrders = $byDates->sortByDesc(fn (array $entry) => $entry['count'])->first();
        $minOrders = $byDates->sortBy(fn (array $entry) => $entry['count'])->first();

        return [
            'orderAvg' => $totalEntries / $daysInRange->count(),
            'maxOrdersDate' => [
                'date' => $maxOrders['date'],
                'count' => $maxOrders['count'],
            ],
            'minOrdersDate' => [
                'date' => $minOrders['date'],
                'count' => $minOrders['count'],
            ],
            'data' => $byDates->toArray(),
        ];
    }

    private function bindList(array $values, array &$bindings): string
    {
        foreach ($values as $value) {
            $bindings[] = $value;
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function getCurrentCount(?string $baseDate = null, string $timezone = 'UTC'): int
    {
        return Order::query()
            ->where('apps_id', $this->app->id)
            ->when($baseDate, fn ($q) => $q->where('created_at', '>=', Carbon::parse($baseDate, $timezone)->timezone('UTC')))
            ->when(! empty($this->orderTypeNames), function ($query) {
                $query->whereHas('orderType', function ($q) {
                    $q->whereIn('name', $this->orderTypeNames);
                });
            })
            ->when($this->productVariantIds, function ($query) {
                $query->whereHas('items', function ($q) {
                    $q->whereIn('variant_id', $this->productVariantIds);
                });
            })
            ->when(! empty($this->providerCompanyIds), function ($query) {
                $db = config('database.connections.commerce.database', 'commerce');
                $ids = implode(',', array_map('intval', $this->providerCompanyIds));
                $query->whereRaw("orders.id IN (SELECT order_id FROM {$db}.order_providers WHERE company_id IN ({$ids}))");
            })
            ->when($this->userEmail, fn ($q) => $q->where('orders.user_email', 'LIKE', $this->userEmail))
            ->whereHas('orderStatus', fn ($q) => $q->whereIn('slug', $this->currentCountStates))
            ->count();
    }

    private function getDailyTurnover($start, $end, string $timezone = 'UTC'): array
    {
        $applyExclusion = $this->turnoverExclusionCallback($start, $end);

        $entries = OrderTransitionHistory::query()
            ->when(! empty($this->excludeStates), $applyExclusion)
            // Unconditional so the Order soft-delete scope always applies.
            ->whereHas('order')
            ->selectRaw("COUNT(DISTINCT order_id) as count, DATE(CONVERT_TZ(changed_at, 'UTC', ?)) as date", [$timezone])
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->where('apps_id', $this->app->id)
            ->when(! empty($this->orderTypeNames), function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('orderType', function ($typeQuery) {
                        $typeQuery->whereIn('name', $this->orderTypeNames);
                    });
                });
            })
            ->when($this->productVariantIds, function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('items', function ($iq) {
                        $iq->whereIn('variant_id', $this->productVariantIds);
                    });
                });
            })
            ->when(! empty($this->providerCompanyIds), function ($query) {
                $db = config('database.connections.commerce.database', 'commerce');
                $ids = implode(',', array_map('intval', $this->providerCompanyIds));
                $query->whereRaw("order_transitions_history.order_id IN (SELECT order_id FROM {$db}.order_providers WHERE company_id IN ({$ids}))");
            })
            ->when($this->userEmail, function ($query) {
                $query->whereHas('order', fn ($q) => $q->where('orders.user_email', 'LIKE', $this->userEmail));
            })
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->initialStates);
            })
            ->get()
            ->keyBy('date');

        $exits = OrderTransitionHistory::query()
            ->when(! empty($this->excludeStates), $applyExclusion)
            // Unconditional so the Order soft-delete scope always applies.
            ->whereHas('order')
            ->selectRaw("COUNT(DISTINCT order_id) as count, DATE(CONVERT_TZ(changed_at, 'UTC', ?)) as date", [$timezone])
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->where('apps_id', $this->app->id)
            ->when(! empty($this->orderTypeNames), function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('orderType', function ($typeQuery) {
                        $typeQuery->whereIn('name', $this->orderTypeNames);
                    });
                });
            })
            ->when($this->productVariantIds, function ($query) {
                $query->whereHas('order', function ($q) {
                    $q->whereHas('items', function ($iq) {
                        $iq->whereIn('variant_id', $this->productVariantIds);
                    });
                });
            })
            ->when(! empty($this->providerCompanyIds), function ($query) {
                $db = config('database.connections.commerce.database', 'commerce');
                $ids = implode(',', array_map('intval', $this->providerCompanyIds));
                $query->whereRaw("order_transitions_history.order_id IN (SELECT order_id FROM {$db}.order_providers WHERE company_id IN ({$ids}))");
            })
            ->when($this->userEmail, function ($query) {
                $query->whereHas('order', fn ($q) => $q->where('orders.user_email', 'LIKE', $this->userEmail));
            })
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->finalStates);
            })
            ->get()
            ->keyBy('date');

        $dates = collect(array_merge($entries->keys()->all(), $exits->keys()->all()))
            ->unique()
            ->sort()
            ->values();

        $byDates = $dates->map(function ($date) use ($entries, $exits) {
            return [
                'date' => $date,
                'entries' => (int) ($entries[$date]->count ?? 0),
                'exits' => (int) ($exits[$date]->count ?? 0),
            ];
        })->toArray();

        $totalEntries = $entries->sum(fn ($entry) => $entry->count ?? 0);
        $totalExits = $exits->sum(fn ($entry) => $entry->count ?? 0);

        $maxExit = $exits->sortByDesc(fn ($entry) => $entry->count ?? 0)->first();
        $maxEntry = $entries->sortByDesc(fn ($entry) => $entry->count ?? 0)->first();

        return [
            'totalEntries' => $entries->sum(fn ($entry) => $entry->count ?? 0),
            'totalExits' => $exits->sum(fn ($entry) => $entry->count ?? 0),
            'exitAvg' => $dates->count() > 0 ? $totalExits / $dates->count() : 0,
            'entryAvg' => $dates->count() > 0 ? $totalEntries / $dates->count() : 0,
            'exitPercentage' => $totalEntries > 0 ? ($totalExits / $totalEntries * 100) : 0,
            'maxExitDate' => [
                'date' => $maxExit?->date,
                'count' => $maxExit?->count,
            ],
            'maxEntryDate' => [
                'date' => $maxEntry?->date,
                'count' => $maxEntry?->count,
            ],
            'data' => $byDates,
        ];
    }

    /**
     * Build the exclusion clause applied to both entries and exits so an order in an excluded
     * state (e.g. cancelled) counts as neither. CURRENT drops it whenever its current status is
     * excluded; IN_RANGE only when it hit an excluded state inside the queried period.
     */
    private function turnoverExclusionCallback(Carbon $start, Carbon $end): callable
    {
        if ($this->excludeMode === OrderStatsExcludeModeEnum::CURRENT) {
            return function ($query) {
                $query->whereDoesntHave(
                    'order',
                    fn ($q) => $q->whereHas('orderStatus', fn ($sq) => $sq->whereIn('slug', $this->excludeStates))
                );
            };
        }

        $excludedOrderIds = OrderTransitionHistory::query()
            ->where('apps_id', $this->app->id)
            ->whereBetween('changed_at', [$start, $end])
            ->whereHas('toStatus', fn ($q) => $q->whereIn('slug', $this->excludeStates))
            ->distinct()
            ->pluck('order_id')
            ->all();

        return fn ($query) => $query->whereNotIn('order_id', $excludedOrderIds);
    }

    private function getByProvider(Carbon $start, Carbon $end): array
    {
        if (empty($this->providers)) {
            return [];
        }

        $caseStatements = [];
        $bindings = [];
        foreach ($this->providers as $provider) {
            $caseStatements[] = "WHEN orders.user_email LIKE ? THEN ?";
            $bindings[] = $provider['emailPattern'];
            $bindings[] = $provider['name'];
        }
        $caseStatements[] = "ELSE 'other'";
        $caseExpression = 'CASE ' . implode(' ', $caseStatements) . ' END';

        return Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('apps_id', $this->app->id)
            ->when(! empty($this->providerCompanyIds), function ($query) {
                $db = config('database.connections.commerce.database', 'commerce');
                $ids = implode(',', array_map('intval', $this->providerCompanyIds));
                $query->whereRaw("orders.id IN (SELECT order_id FROM {$db}.order_providers WHERE company_id IN ({$ids}))");
            })
            ->when($this->userEmail, fn ($q) => $q->where('orders.user_email', $this->userEmail))
            ->selectRaw("
                ({$caseExpression}) AS provider_name,
                COUNT(DISTINCT orders.id) AS total_count,
                SUM(orders.total_net_amount) AS total_amount
            ", $bindings)
            ->groupByRaw('provider_name')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->provider_name,
                'count' => (int) $item->total_count,
                'totalAmount' => (float) ($item->total_amount ?? 0),
            ])
            ->toArray();
    }
}
