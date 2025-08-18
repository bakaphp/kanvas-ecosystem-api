<?php

namespace Kanvas\Souk\Orders\Actions;

use DateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;

class GetOrderStatsAction
{
    public function __construct(
        protected Apps $app,
        protected array $initialStates,
        protected array $finalStates,
        protected array $currentCountStates = [],
    ) {
    }

    public function execute(
        ?string $date = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $baseDate = null,
        string $timezone = 'UTC'
    ): array {
        if ($date && (! $startDate || ! $endDate)) {
            $start = Carbon::parse($date, $timezone)->startOfDay()->timezone('UTC');
            $end   = Carbon::parse($date, $timezone)->endOfDay()->timezone('UTC');
        } else {
            $start = $startDate ? Carbon::parse($startDate, $timezone)->startOfDay()->timezone('UTC') : now()->startOfDay()->timezone('UTC');
            $end = $endDate ? Carbon::parse($endDate, $timezone)->endOfDay()->timezone('UTC') : now()->endOfDay()->timezone('UTC');
        }

        $currentCount = $this->getCurrentCount($baseDate, $timezone);
        $dailyTurnover = $this->getDailyTurnover($start, $end);
        $ordersInPeriod = $this->getOrdersInPeriod($start, $end, $currentCount);

        return [
            'period' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ],
            'ordersInPeriod' => $ordersInPeriod,
            'currentCount' => $currentCount,
            'dailyTurnover' => $dailyTurnover,
            'averageRotation' => $this->getAverageRotation($start, $end),
            'orderRotationAvg' => $ordersInPeriod["orderAvg"] > 0 ? ($dailyTurnover["totalExits"] / $ordersInPeriod["orderAvg"]) : 0
        ];
    }

    /**
     * Get average time for order from initial state to final state
     */
    private function getAverageRotation($start, $end): array
    {
        $connection = (new OrderTransitionHistory())->getConnectionName();

        $initialSubQuery = OrderTransitionHistory::query()
            ->selectRaw("order_id, MIN(changed_at) as initial_date")
            ->whereBetween('changed_at', [$start, $end])
            ->where('apps_id', $this->app->id)
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->initialStates);
            })
            ->groupBy('order_id')
            ->getQuery();

        $finalSubQuery = OrderTransitionHistory::query()
            ->selectRaw("order_id, MAX(changed_at) as final_date")
            ->whereBetween('changed_at', [$start, $end])
            ->where('apps_id', $this->app->id)
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->finalStates);
            })
            ->groupBy('order_id')
            ->getQuery();

        $rotationQuery = DB::connection($connection)->table(DB::raw("({$initialSubQuery->toSql()}) as initial"))
            ->mergeBindings($initialSubQuery)
            ->join(DB::raw("({$finalSubQuery->toSql()}) as final"), 'initial.order_id', '=', 'final.order_id')
            ->mergeBindings($finalSubQuery)
            ->selectRaw("initial.order_id, initial.initial_date, final.final_date, TIMESTAMPDIFF(MINUTE, initial.initial_date, final.final_date) / 60 as diff_hours")
            ->get();

        return [
            'orders' => $rotationQuery->map(fn ($item) => [
                'orderId' => $item->order_id,
                'initialDate' => $item->initial_date,
                'finalDate' => $item->final_date,
                'time' => $item->diff_hours,
            ]),
            'averageTime' => $rotationQuery->avg('diff_hours') ?? 0,
        ];
    }

    /**
     * Get orders in period grouped by date and state
     */
    private function getOrdersInPeriod($start, $end, $currentCount = null): array
    {
        $dateList = $this->generateDateList($start, $end);

        $dateRangeSub = DB::raw("(SELECT " . implode(" UNION ALL SELECT ", $dateList) . ") as date_list(date_val)");

        $activeOrders = DB::raw("
            (SELECT DISTINCT order_id 
             FROM order_transitions_history 
             WHERE apps_id = {$this->app->id} 
               AND is_deleted = 0 
               AND changed_at <= '{$end} 23:59:59') AS active_orders
        ");

        $latestStatus = DB::raw("
            (
                SELECT * FROM (
                    SELECT 
                        order_id,
                        to_status_id,
                        changed_at,
                        ended_at,
                        ROW_NUMBER() OVER (PARTITION BY order_id, date(changed_at) ORDER BY changed_at DESC) AS rn
                    FROM order_transitions_history
                    WHERE apps_id = {$this->app->id}
                      AND is_deleted = 0
                ) ranked
                WHERE rn = 1
            ) AS latest_status
        ");

        $results = DB::connection('commerce')->query()
            ->fromSub(function ($query) use ($dateRangeSub) {
                $query->selectRaw('date_val as report_date')->from($dateRangeSub);
            }, 'date_range')
            ->crossJoin($activeOrders)
            ->leftJoin($latestStatus, function ($join) {
                $join->on('active_orders.order_id', '=', 'latest_status.order_id')
                    ->whereRaw('latest_status.changed_at <= CONCAT(date_range.report_date, " 23:59:59")')
                    ->whereRaw('(latest_status.ended_at IS NULL OR latest_status.ended_at > CONCAT(date_range.report_date, " 23:59:59"))');
            })
            ->join('order_statuses', 'latest_status.to_status_id', '=', 'order_statuses.id')
            ->when(! empty($this->currentCountStates), function ($query) {
                $query->whereIn('order_statuses.slug', $this->currentCountStates);
            })
            ->selectRaw('
                order_statuses.slug as state, 
                COUNT(DISTINCT active_orders.order_id) as count, 
                date_range.report_date as date
            ')
            ->groupBy('order_statuses.slug', 'date_range.report_date')
            ->orderBy('date_range.report_date')
            ->get();

        $daysInRange = collect($this->generateDateList($start, $end))
            ->map(fn ($date) => trim($date, "'"));

        $groupedResults = $results->groupBy('date');

        $byDates = $daysInRange->map(function ($date) use ($groupedResults) {
            $group = $groupedResults->get($date, collect());

            return [
                'date' => $date,
                'count' => $group?->sum('count') ?? 0,
                'states' => $group?->map(fn ($item) => [
                    'state' => $item->state ?? 'Unknown',
                    'count' => (int) $item->count,
                ])->toArray() ?? [],
            ];
        });

        $totalEntries = $byDates->sum(fn ($entry) => $entry["count"] ?? 0);

        $maxOrders = $byDates->sortByDesc(fn ($entry) => $entry["count"] ?? 0)->first();
        $minOrders = $byDates->sortBy(fn ($entry) => $entry["count"] ?? 0)->first();

        return [
            "orderAvg" => $totalEntries / $daysInRange->count(),
            "maxOrdersDate" => [
                "date" => $maxOrders["date"],
                "count" => $maxOrders["count"]
            ],
            "minOrdersDate" => [
                "date" => $minOrders["date"],
                "count" => $minOrders["count"]
            ],
            "data" => $byDates->toArray()
        ];
    }

    private function generateDateList($start, $end): array
    {
        $dates = [];
        $startDate = new DateTime($start);
        $endDate = new DateTime($end);

        while ($startDate <= $endDate) {
            $dates[] = "'" . $startDate->format('Y-m-d') . "'";
            $startDate->modify('+1 day');
        }

        return $dates;
    }

    private function getCurrentCount(?string $baseDate = null, string $timezone = 'UTC'): int
    {
        return Order::query()
            ->where('apps_id', $this->app->id)
            ->when($baseDate, fn ($q) => $q->where('created_at', '>=', Carbon::parse($baseDate, $timezone)->timezone('UTC')))
            ->whereHas('orderStatus', fn ($q) => $q->whereIn('slug', $this->currentCountStates))
            ->count();
    }

    private function getDailyTurnover($start, $end): array
    {
        $entries = OrderTransitionHistory::query()
            ->selectRaw("COUNT(DISTINCT order_id) as count, DATE(changed_at) as date")
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->where('apps_id', $this->app->id)
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('slug', $this->initialStates);
            })
            ->get()
            ->keyBy('date');

        $exits = OrderTransitionHistory::query()
            ->selectRaw("COUNT(DISTINCT order_id) as count, DATE(changed_at) as date")
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->where('apps_id', $this->app->id)
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

        $maxExit =  $exits->sortByDesc(fn ($entry) => $entry->count ?? 0)->first();
        $maxEntry = $entries->sortByDesc(fn ($entry) => $entry->count ?? 0)->first();

        return [
            "totalEntries" => $entries->sum(fn ($entry) => $entry->count ?? 0),
            "totalExits" => $exits->sum(fn ($entry) => $entry->count ?? 0),
            "exitAvg" => $dates->count() > 0 ? $totalExits / $dates->count() : 0,
            "entryAvg" => $dates->count() > 0 ? $totalEntries / $dates->count() : 0,
            "exitPercentage" => $totalEntries > 0 ? ($totalExits / $totalEntries * 100) : 0,
            "maxExitDate" => [
                "date" => $maxExit->date,
                "count" => $maxExit->count
            ],
            "maxEntryDate" => [
                "date" => $maxEntry->date,
                "count" => $maxEntry->count
            ],
            "data" => $byDates
        ];
    }
}
