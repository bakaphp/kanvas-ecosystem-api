<?php

namespace Kanvas\Souk\Orders\Actions;

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

    public function execute(?string $date, ?string $startDate, ?string $endDate, ?string $timezone = 'UTC'): array
    {
        if ($date && (! $startDate || ! $endDate)) {
            $start = Carbon::parse($date, $timezone)->startOfDay()->timezone('UTC');
            $end   = Carbon::parse($date, $timezone)->endOfDay()->timezone('UTC');
        } else {
            $start = $startDate ? Carbon::parse($startDate, $timezone)->startOfDay()->timezone('UTC') : now()->startOfDay()->timezone('UTC');
            $end = $endDate ? Carbon::parse($endDate, $timezone)->endOfDay()->timezone('UTC') : now()->endOfDay()->timezone('UTC');
        }

        return [
            'period' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ],
            'ordersInPeriod' => $this->getOrdersInPeriod($start, $end),
            'currentCount' => $this->getCurrentCount(),
            'dailyTurnover' => $this->getDailyTurnover($start, $end),
            'averageRotation' => $this->getAverageRotation($start, $end),
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
                $query->whereIn('name', $this->initialStates);
            })
            ->groupBy('order_id')
            ->getQuery();

        $finalSubQuery = OrderTransitionHistory::query()
            ->selectRaw("order_id, MAX(changed_at) as final_date")
            ->whereBetween('changed_at', [$start, $end])
            ->where('apps_id', $this->app->id)
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('name', $this->finalStates);
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
    private function getOrdersInPeriod($start, $end): array
    {
        return DB::connection('commerce')->query()
        ->fromSub(function ($query) use ($start, $end) {
            $query->from('order_transitions_history')
                ->selectRaw('
                    order_transitions_history.id,
                    order_transitions_history.order_id,
                    order_transitions_history.to_status_id,
                    DATE(order_transitions_history.changed_at) as date,
                    order_transitions_history.is_deleted,
                    ROW_NUMBER() OVER (PARTITION BY order_id, DATE(changed_at) ORDER BY changed_at DESC) as rn
                ')
                ->whereBetween('changed_at', [$start, $end])
                ->where('order_transitions_history.apps_id', $this->app->id);
        }, 'latest_transitions')
        ->join('order_statuses', 'latest_transitions.to_status_id', '=', 'order_statuses.id')
        ->where('latest_transitions.rn', 1)
        ->when(! empty($this->currentCountStates), function ($query) {
            $query->whereIn('order_statuses.name', $this->currentCountStates);
        })
        ->selectRaw('order_statuses.name as state, COUNT(*) as count, latest_transitions.date')
        ->groupBy('order_statuses.name', 'latest_transitions.date')
        ->orderBy('latest_transitions.date')
        ->get()
        ->map(fn ($item) => [
            'date' => $item->date,
            'state' => $item->state ?? 'Unknown',
            'count' => (int) $item->count,
        ])
        ->toArray();
    }

    private function getCurrentCount(): int
    {
        return Order::query()
            ->where('apps_id', $this->app->id)
            ->whereHas('orderStatus', fn ($q) => $q->whereIn('name', $this->currentCountStates))
            ->count();
    }

    private function getDailyTurnover($start, $end): array
    {
        $entries = OrderTransitionHistory::query()
            ->selectRaw("COUNT(*) as count, DATE(changed_at) as date")
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->where('apps_id', $this->app->id)
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('name', $this->initialStates);
            })
            ->get()
            ->keyBy('date');

        $exits = OrderTransitionHistory::query()
            ->selectRaw("COUNT(*) as count, DATE(changed_at) as date")
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->where('apps_id', $this->app->id)
            ->whereHas('toStatus', function ($query) {
                $query->whereIn('name', $this->finalStates);
            })
            ->get()
            ->keyBy('date');

        $dates = collect(array_merge($entries->keys()->all(), $exits->keys()->all()))
            ->unique()
            ->sort()
            ->values();

        return $dates->map(function ($date) use ($entries, $exits) {
            return [
                'date' => $date,
                'entries' => (int) ($entries[$date]->count ?? 0),
                'exits' => (int) ($exits[$date]->count ?? 0),
            ];
        })->toArray();
    }
}
