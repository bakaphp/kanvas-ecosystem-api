<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\GetOrderPaymentStatsAction;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class OrderReportService
{
    /**
     * Statuses that take an order out of the operational pipeline — a cancelled order is not a
     * backlog item no matter what its payment/fulfillment columns still say.
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function orderTypes(): array
    {
        $types = OrderTypes::query()
            ->where('apps_id', $this->app->getId())
            ->whereIn('companies_id', [$this->company->getId(), 0])
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'count' => $types->count(),
            'order_types' => $types->map(fn ($t): array => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
            ])->all(),
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     *
     * @return array<string, mixed>
     */
    public function breakdown(?string $groupBy, ?array $orderTypeNames, ?string $since, ?string $until): array
    {
        $dimension = strtolower($groupBy ?? 'status') === 'type' ? 'type' : 'status';
        $typeIds = $this->resolveOrderTypeIds($orderTypeNames);
        $base = fn (): Builder => $this->baseQuery($typeIds, $since, $until);

        return [
            'group_by' => $dimension,
            'total_orders' => $base()->count(),
            'total_revenue' => round((float) $base()->sum('total_gross_amount'), 2),
            'groups' => $dimension === 'type' ? $this->groupByType($base()) : $this->groupByStatus($base()),
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     *
     * @return array<string, mixed>
     */
    public function paymentStats(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?string $timezone,
        ?string $periodBreakdown
    ): array {
        $timezone = $this->validTimezone($timezone);
        $since = $since !== null && $since !== '' ? $since : $this->firstOrderDate($timezone);
        $until = $until !== null && $until !== '' ? $until : Carbon::now($timezone)->toDateString();
        $breakdown = strtoupper(trim($periodBreakdown ?? 'MONTH'));
        $breakdown = in_array($breakdown, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true) ? $breakdown : 'MONTH';

        $stats = new GetOrderPaymentStatsAction(
            app: $this->app,
            orderTypeNames: array_values(array_filter($orderTypeNames ?? [])),
            company: $this->company,
        )->execute(
            startDate: $since,
            endDate: $until,
            timezone: $timezone,
            groupPeriods: [],
            periodBreakdown: $breakdown,
        );

        $orders = (int) $stats['ordersInPeriod']['count'];
        $total = round((float) $stats['ordersInPeriod']['totalAmount'], 2);
        $byTransaction = $stats['ordersInPeriod']['byTransaction'];

        return [
            'date_anchor' => 'payment',
            'timezone' => $timezone,
            'period' => $stats['period'],
            'orders' => $orders,
            'total_amount' => $total,
            'average_order_amount' => $orders > 0 ? round($total / $orders, 2) : 0.0,
            'by_payment_method' => [
                'card' => ['orders' => (int) $byTransaction['card'], 'amount' => round((float) $byTransaction['cardAmount'], 2)],
                'other' => ['orders' => (int) $byTransaction['transfer'], 'amount' => round((float) $byTransaction['transferAmount'], 2)],
            ],
            'by_service' => $stats['ordersInPeriod']['byServices'],
            'period_breakdown' => $breakdown,
            'by_period' => $stats['byPeriod'],
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     *
     * @return array<string, mixed>
     */
    public function trend(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?string $groupBy,
        bool $paidOnly
    ): array {
        $interval = in_array(strtolower($groupBy ?? 'month'), ['day', 'week', 'month'], true)
            ? strtolower($groupBy ?? 'month')
            : 'month';
        $typeIds = $this->resolveOrderTypeIds($orderTypeNames);

        $rows = $this->baseQuery($typeIds, $since, $until)
            ->when($paidOnly, fn ($q) => $q->where('orders.payment_status', PaymentStatusEnum::PAID->value))
            ->selectRaw(
                $this->periodExpression($interval) . ' as period, '
                . 'COUNT(*) as orders, '
                . 'COALESCE(SUM(total_gross_amount), 0) as gross_revenue, '
                . 'COALESCE(SUM(total_net_amount), 0) as net_revenue'
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r): array => [
                'period' => (string) $r->period,
                'orders' => (int) $r->orders,
                'gross_revenue' => round((float) $r->gross_revenue, 2),
                'net_revenue' => round((float) $r->net_revenue, 2),
            ])->all();

        $periods = count($rows);
        $totalOrders = array_sum(array_column($rows, 'orders'));
        $totalNet = array_sum(array_column($rows, 'net_revenue'));
        $byOrders = collect($rows)->sortByDesc('orders');

        return [
            'group_by' => $interval,
            'paid_only' => $paidOnly,
            'periods' => $periods,
            'total_orders' => $totalOrders,
            'total_gross_revenue' => round((float) array_sum(array_column($rows, 'gross_revenue')), 2),
            'total_net_revenue' => round((float) $totalNet, 2),
            'average_orders_per_period' => $periods > 0 ? round($totalOrders / $periods, 2) : 0.0,
            'average_net_revenue_per_period' => $periods > 0 ? round((float) $totalNet / $periods, 2) : 0.0,
            'peak_period' => $byOrders->first(),
            'lowest_period' => $byOrders->last(),
            'series' => $rows,
        ];
    }

    /**
     * Pipeline health: where orders sit on the payment and fulfillment axes, plus the two backlogs
     * that matter operationally (collected-but-not-shipped, and shipped-or-open-but-not-collected).
     *
     * @param string[]|null $orderTypeNames
     *
     * @return array<string, mixed>
     */
    public function fulfillmentStats(?array $orderTypeNames, ?string $since, ?string $until): array
    {
        $typeIds = $this->resolveOrderTypeIds($orderTypeNames);
        $base = fn (): Builder => $this->baseQuery($typeIds, $since, $until);
        $open = fn (): Builder => $base()->whereNotIn('orders.status', OrderStatusEnum::closedValues());

        $paid = PaymentStatusEnum::PAID->value;
        $fulfilled = OrderFulfillmentStatusEnum::COMPLETED->value;

        return [
            'total_orders' => $base()->count(),
            'total_net_amount' => round((float) $base()->sum('total_net_amount'), 2),
            'by_payment_status' => $this->groupByColumn($base(), 'payment_status'),
            'by_fulfillment_status' => $this->groupByColumn($base(), 'fulfillment_status'),
            'backlog' => [
                'paid_not_fulfilled' => $this->countAndAmount(
                    $open()->where('orders.payment_status', $paid)
                        ->where(fn ($q) => $q->where('orders.fulfillment_status', '!=', $fulfilled)
                            ->orWhereNull('orders.fulfillment_status'))
                ),
                'unpaid' => $this->countAndAmount(
                    $open()->where(fn ($q) => $q->where('orders.payment_status', '!=', $paid)
                        ->orWhereNull('orders.payment_status'))
                ),
            ],
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     *
     * @return array<string, mixed>
     */
    public function providerStats(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        int $limit
    ): array {
        $typeIds = $this->resolveOrderTypeIds($orderTypeNames);
        $pivot = OrderProvider::getQualifiedTableName();

        $rows = $this->baseQuery($typeIds, $since, $until)
            ->join($pivot . ' as order_providers', 'order_providers.order_id', '=', 'orders.id')
            ->selectRaw(
                'order_providers.company_id as company_id, '
                . 'COUNT(*) as orders, '
                . 'COALESCE(SUM(total_net_amount), 0) as net_revenue, '
                . 'COALESCE(SUM(commission_amount), 0) as commission, '
                . 'COALESCE(SUM(provider_amount), 0) as provider_payout'
            )
            ->groupBy('order_providers.company_id')
            ->orderByDesc('net_revenue')
            ->limit($limit)
            ->get();

        $names = Companies::query()
            ->whereIn('id', $rows->pluck('company_id')->filter())
            ->pluck('name', 'id');

        return [
            'providers' => $rows->map(fn ($r): array => [
                'company_id' => (int) $r->company_id,
                'company' => (string) ($names->get($r->company_id) ?? ('company #' . $r->company_id)),
                'orders' => (int) $r->orders,
                'net_revenue' => round((float) $r->net_revenue, 2),
                'commission' => round((float) $r->commission, 2),
                'provider_payout' => round((float) $r->provider_payout, 2),
            ])->all(),
            'orders_without_provider' => $this->baseQuery($typeIds, $since, $until)
                ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from($pivot . ' as op')
                    ->whereColumn('op.order_id', 'orders.id'))
                ->count(),
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     *
     * @return array<string, mixed>
     */
    public function commissionStats(?array $orderTypeNames, ?string $since, ?string $until): array
    {
        $typeIds = $this->resolveOrderTypeIds($orderTypeNames);

        $row = $this->baseQuery($typeIds, $since, $until)
            ->whereNotNull('orders.commission_rate')
            ->selectRaw(
                'COUNT(*) as order_count, '
                . 'COALESCE(SUM(total_net_amount), 0) as total_revenue, '
                . 'COALESCE(SUM(commission_amount), 0) as total_commission, '
                . 'COALESCE(SUM(provider_amount), 0) as total_provider_amount'
            )->first();

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'total_revenue' => round((float) ($row->total_revenue ?? 0), 2),
            'total_commission' => round((float) ($row->total_commission ?? 0), 2),
            'total_provider_amount' => round((float) ($row->total_provider_amount ?? 0), 2),
        ];
    }

    /**
     * @param int[]|null $typeIds
     */
    private function baseQuery(?array $typeIds, ?string $since, ?string $until): Builder
    {
        return Order::query()
            ->where('orders.apps_id', $this->app->getId())
            ->where('orders.companies_id', $this->company->getId())
            ->where('orders.is_deleted', false)
            ->when($typeIds !== null, fn ($q) => $q->whereIn('orders.order_types_id', $typeIds))
            ->when($since !== null && $since !== '', fn ($q) => $q->whereDate('orders.created_at', '>=', $since))
            ->when($until !== null && $until !== '', fn ($q) => $q->whereDate('orders.created_at', '<=', $until));
    }

    private function validTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            return 'UTC';
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Unknown timezone "' . $timezone . '". Use an IANA name such as America/Santo_Domingo.');
        }

        return $timezone;
    }

    private function firstOrderDate(string $timezone): string
    {
        $first = $this->baseQuery(null, null, null)->min('orders.created_at');

        return $first !== null
            ? Carbon::parse($first)->timezone($timezone)->toDateString()
            : Carbon::now($timezone)->toDateString();
    }

    private function resolveOrderTypeIds(?array $names): ?array
    {
        $names = array_filter(array_map('trim', $names ?? []), fn (string $name): bool => $name !== '');

        return $names === [] ? null : OrderTypes::idsForNames($this->app, $this->company, $names);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupByStatus(Builder $query): array
    {
        return $query
            ->selectRaw('status, COUNT(*) as orders, SUM(total_gross_amount) as revenue')
            ->groupBy('status')
            ->orderByDesc('orders')
            ->get()
            ->map(fn ($r): array => [
                'status' => (string) ($r->status ?? 'unknown'),
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupByType(Builder $query): array
    {
        $rows = $query
            ->selectRaw('order_types_id, COUNT(*) as orders, SUM(total_gross_amount) as revenue')
            ->groupBy('order_types_id')
            ->orderByDesc('orders')
            ->get();

        $names = OrderTypes::query()
            ->whereIn('id', $rows->pluck('order_types_id')->filter())
            ->pluck('name', 'id');

        return $rows->map(fn ($r): array => [
            'order_type' => $r->order_types_id !== null
                ? (string) ($names->get($r->order_types_id) ?? ('type #' . $r->order_types_id))
                : 'untyped',
            'orders' => (int) $r->orders,
            'revenue' => round((float) $r->revenue, 2),
        ])->all();
    }

    /**
     * Week buckets start on Monday so a "last 8 weeks" series lines up with how operations reads a
     * calendar week, not with MySQL's Sunday-based WEEK().
     */
    private function periodExpression(string $interval): string
    {
        return match ($interval) {
            'day' => 'DATE(orders.created_at)',
            'week' => 'DATE(DATE_SUB(orders.created_at, INTERVAL WEEKDAY(orders.created_at) DAY))',
            default => "DATE_FORMAT(orders.created_at, '%Y-%m-01')",
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupByColumn(Builder $query, string $column): array
    {
        return $query
            ->selectRaw($column . ' as bucket, COUNT(*) as orders, COALESCE(SUM(total_net_amount), 0) as amount')
            ->groupBy('bucket')
            ->orderByDesc('orders')
            ->get()
            ->map(fn ($r): array => [
                $column => (string) ($r->bucket ?? 'unknown'),
                'orders' => (int) $r->orders,
                'amount' => round((float) $r->amount, 2),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function countAndAmount(Builder $query): array
    {
        $row = $query
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_net_amount), 0) as amount')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
    }
}
