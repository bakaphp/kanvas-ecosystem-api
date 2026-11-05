<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use Baka\Support\Str;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Souk\Orders\Actions\GetOrderPaymentStatsAction;
use Kanvas\Souk\Orders\DataTransferObject\OrderReportScope;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class OrderReportService
{
    /**
     * One customer across orders: the CRM person when the order has one, else the auth user, else
     * the email. `people_id` is NOT NULL on `orders`, so a guest order carries 0 and must fall through.
     * An order with none of the three has no customer and is counted separately, never guessed.
     */
    private const string CUSTOMER_KEY = 'COALESCE('
        . "CONCAT('p:', NULLIF(orders.people_id, 0)), "
        . "CONCAT('u:', NULLIF(orders.users_id, 0)), "
        . "CONCAT('e:', LOWER(NULLIF(TRIM(orders.user_email), '')))"
        . ')';

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
        $types = $this->orderTypesQuery()
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'count' => $types->count(),
            'order_types' => $types->map(fn ($t): array => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
            ])->all(),
            'tags' => $this->orderTagsInUse(),
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     * @param string[]|null $tagNames
     *
     * @return array<string, mixed>
     */
    public function breakdown(
        ?string $groupBy,
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?array $tagNames = null
    ): array {
        $dimension = strtolower($groupBy ?? 'status') === 'type' ? 'type' : 'status';
        $scope = $this->resolveScope($orderTypeNames, $tagNames);
        $base = fn (): Builder => $this->baseQuery($scope, $since, $until);

        return [
            'group_by' => $dimension,
            'total_orders' => $base()->count(),
            'total_revenue' => round((float) $base()->sum('total_gross_amount'), 2),
            'groups' => $dimension === 'type' ? $this->groupByType($base()) : $this->groupByStatus($base()),
        ];
    }

    /**
     * @param string[]|null $orderTypeNames
     * @param string[]|null $tagNames
     *
     * @return array<string, mixed>
     */
    public function paymentStats(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?string $timezone,
        ?string $periodBreakdown,
        ?array $tagNames = null
    ): array {
        $timezone = $this->validTimezone($timezone);
        $scope = $this->resolveScope($orderTypeNames, $tagNames);
        $since = $since !== null && $since !== '' ? $since : $this->firstOrderDate($timezone);
        $until = $until !== null && $until !== '' ? $until : Carbon::now($timezone)->toDateString();
        $breakdown = strtoupper(trim($periodBreakdown ?? 'MONTH'));
        $breakdown = in_array($breakdown, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true) ? $breakdown : 'MONTH';

        $stats = new GetOrderPaymentStatsAction(
            app: $this->app,
            orderTypeNames: array_values(array_filter($orderTypeNames ?? [])),
            company: $this->company,
            orderIds: $scope->orderIds,
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
     * @param string[]|null $tagNames
     *
     * @return array<string, mixed>
     */
    public function trend(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?string $groupBy,
        bool $paidOnly,
        ?string $dateAnchor = null,
        ?string $timezone = null,
        ?array $tagNames = null
    ): array {
        $interval = in_array(strtolower($groupBy ?? 'month'), ['day', 'week', 'month'], true)
            ? strtolower($groupBy ?? 'month')
            : 'month';
        $anchor = strtolower(trim($dateAnchor ?? 'created')) === 'paid' ? 'paid' : 'created';
        $column = $anchor === 'paid' ? 'paid_at' : 'created_at';
        $timezone = $this->validTimezone($timezone);
        $scope = $this->resolveScope($orderTypeNames, $tagNames);

        $cohorts = $this->newCustomersByPeriod(
            $scope,
            $since,
            $until,
            $interval,
            $column,
            $paidOnly,
            $timezone
        );

        $orders = $this->baseQuery(
            $scope,
            $since,
            $until,
            $column,
            $timezone
        );

        $rows = $this->anchorFilters($orders, $column, $paidOnly)
            ->selectRaw(
                $this->periodExpression($interval, $this->localizedColumn('orders.' . $column, $timezone)) . ' as period, '
                . 'COUNT(*) as orders, '
                . 'COALESCE(SUM(total_gross_amount), 0) as gross_revenue, '
                . 'COALESCE(SUM(total_net_amount), 0) as net_revenue, '
                . 'SUM(' . self::CUSTOMER_KEY . ' IS NULL) as orders_without_customer'
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r): array => [
                'period' => (string) $r->period,
                'orders' => (int) $r->orders,
                'gross_revenue' => round((float) $r->gross_revenue, 2),
                'net_revenue' => round((float) $r->net_revenue, 2),
                'new_customers' => (int) ($cohorts[(string) $r->period]['new_customers'] ?? 0),
                'new_to_company' => (int) ($cohorts[(string) $r->period]['new_to_company'] ?? 0),
                'orders_without_customer' => (int) $r->orders_without_customer,
            ])->all();

        $periods = count($rows);
        $totalOrders = array_sum(array_column($rows, 'orders'));
        $totalNet = array_sum(array_column($rows, 'net_revenue'));
        $byOrders = collect($rows)->sortByDesc('orders');

        return [
            'group_by' => $interval,
            'date_anchor' => $anchor,
            'timezone' => $timezone,
            'paid_only' => $paidOnly,
            'periods' => $periods,
            'total_orders' => $totalOrders,
            'total_gross_revenue' => round((float) array_sum(array_column($rows, 'gross_revenue')), 2),
            'total_net_revenue' => round((float) $totalNet, 2),
            'total_new_customers' => array_sum(array_column($rows, 'new_customers')),
            'total_new_to_company' => array_sum(array_column($rows, 'new_to_company')),
            'total_orders_without_customer' => array_sum(array_column($rows, 'orders_without_customer')),
            'average_orders_per_period' => $periods > 0 ? round($totalOrders / $periods, 2) : 0.0,
            'average_net_revenue_per_period' => $periods > 0 ? round((float) $totalNet / $periods, 2) : 0.0,
            'peak_period' => $byOrders->first(),
            'lowest_period' => $byOrders->last(),
            'peak_gross_revenue_period' => collect($rows)->sortByDesc('gross_revenue')->first(),
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
    public function fulfillmentStats(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?array $tagNames = null
    ): array {
        $scope = $this->resolveScope($orderTypeNames, $tagNames);
        $base = fn (): Builder => $this->baseQuery($scope, $since, $until);
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
        int $limit,
        ?array $tagNames = null
    ): array {
        $scope = $this->resolveScope($orderTypeNames, $tagNames);
        $pivot = OrderProvider::getQualifiedTableName();

        $rows = $this->baseQuery($scope, $since, $until)
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
            'orders_without_provider' => $this->baseQuery($scope, $since, $until)
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
    public function commissionStats(
        ?array $orderTypeNames,
        ?string $since,
        ?string $until,
        ?array $tagNames = null
    ): array {
        $scope = $this->resolveScope($orderTypeNames, $tagNames);

        $row = $this->baseQuery($scope, $since, $until)
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

    private function baseQuery(
        OrderReportScope $scope,
        ?string $since,
        ?string $until,
        string $column = 'created_at',
        string $timezone = 'UTC'
    ): Builder {
        return Order::query()
            ->where('orders.apps_id', $this->app->getId())
            ->where('orders.companies_id', $this->company->getId())
            ->where('orders.is_deleted', false)
            ->when($scope->typeIds !== null, fn ($q) => $q->whereIn('orders.order_types_id', $scope->typeIds))
            ->when($scope->orderIds !== null, fn ($q) => $q->whereIn('orders.id', $scope->orderIds))
            ->when(
                $since !== null && $since !== '',
                fn ($q) => $q->where('orders.' . $column, '>=', Carbon::parse($since, $timezone)->startOfDay()->utc())
            )
            ->when(
                $until !== null && $until !== '',
                fn ($q) => $q->where('orders.' . $column, '<=', Carbon::parse($until, $timezone)->endOfDay()->utc())
            );
    }

    /**
     * A paid anchor only sees orders that were paid; paid_only additionally drops orders whose
     * payment is still open. The trend series and the cohort it merges into must agree on both.
     */
    private function anchorFilters(Builder $query, string $column, bool $paidOnly): Builder
    {
        return $query
            ->when($column === 'paid_at', fn ($q) => $q->whereNotNull('orders.paid_at'))
            ->when($paidOnly, fn ($q) => $q->where('orders.payment_status', PaymentStatusEnum::PAID->value));
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

    private function localizedColumn(string $column, string $timezone): string
    {
        return $timezone === 'UTC'
            ? $column
            : "CONVERT_TZ({$column}, 'UTC', '{$timezone}')";
    }

    /**
     * New customers per period, keyed by the period string `trend()` buckets on.
     *
     * A customer is new in the period that holds their FIRST order within the selected order types,
     * computed over the vertical's whole history and only then cut to the requested window. Of those,
     * `new_to_company` are the ones with no earlier order in any order type: their first order anywhere
     * is this one. With no order-type filter the two numbers are the same. A customer is new in exactly
     * one period, so the series sums to the vertical's distinct customers. A tag filter narrows the
     * vertical the same way a type does; the company side always reads every order.
     *
     * @return array<string, array{new_customers: int, new_to_company: int}>
     */
    private function newCustomersByPeriod(
        OrderReportScope $scope,
        ?string $since,
        ?string $until,
        string $interval,
        string $column,
        bool $paidOnly,
        string $timezone
    ): array {
        $period = $this->periodExpression($interval, $this->localizedColumn('vertical.first_at', $timezone));
        $vertical = $this->firstOrderPerCustomer($scope, $column, $paidOnly);

        return $vertical->newQuery()
            ->fromSub($vertical, 'vertical')
            ->joinSub(
                $this->firstOrderPerCustomer(OrderReportScope::everything(), $column, $paidOnly),
                'company',
                'company.customer_key',
                '=',
                'vertical.customer_key'
            )
            ->when(
                $since !== null && $since !== '',
                fn ($q) => $q->where('vertical.first_at', '>=', Carbon::parse($since, $timezone)->startOfDay()->utc())
            )
            ->when(
                $until !== null && $until !== '',
                fn ($q) => $q->where('vertical.first_at', '<=', Carbon::parse($until, $timezone)->endOfDay()->utc())
            )
            ->selectRaw(
                $period . ' as period, '
                . 'COUNT(*) as new_customers, '
                . 'SUM(company.first_at >= vertical.first_at) as new_to_company'
            )
            ->groupBy('period')
            ->get()
            ->mapWithKeys(fn ($r): array => [
                (string) $r->period => [
                    'new_customers' => (int) $r->new_customers,
                    'new_to_company' => (int) $r->new_to_company,
                ],
            ])
            ->all();
    }

    private function firstOrderPerCustomer(OrderReportScope $scope, string $column, bool $paidOnly): QueryBuilder
    {
        return $this->anchorFilters($this->baseQuery($scope, null, null), $column, $paidOnly)
            ->whereRaw(self::CUSTOMER_KEY . ' IS NOT NULL')
            ->selectRaw(self::CUSTOMER_KEY . ' as customer_key, MIN(orders.' . $column . ') as first_at')
            ->groupBy('customer_key')
            ->toBase();
    }

    private function firstOrderDate(string $timezone): string
    {
        $first = $this->baseQuery(OrderReportScope::everything(), null, null)->min('orders.created_at');

        return $first !== null
            ? Carbon::parse($first)->timezone($timezone)->toDateString()
            : Carbon::now($timezone)->toDateString();
    }

    /**
     * An unknown name is an error naming the valid ones, never an empty result: an agent reads an
     * empty series as "try another spelling" and loops until its run budget kills the turn.
     *
     * @param string[]|null $typeNames
     * @param string[]|null $tagNames
     */
    private function resolveScope(?array $typeNames, ?array $tagNames): OrderReportScope
    {
        $typeNames = $this->cleanNames($typeNames);
        $tagNames = $this->cleanNames($tagNames);

        return new OrderReportScope(
            typeIds: $typeNames === [] ? null : $this->resolveOrderTypeIds($typeNames),
            orderIds: $tagNames === [] ? null : $this->taggedOrderIds($this->resolveTagIds($tagNames)),
        );
    }

    /**
     * @param string[]|null $names
     *
     * @return list<string>
     */
    private function cleanNames(?array $names): array
    {
        return array_values(array_filter(
            array_map(trim(...), $names ?? []),
            fn (string $name): bool => $name !== ''
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return int[]
     */
    private function resolveOrderTypeIds(array $names): array
    {
        $found = $this->orderTypesQuery()
            ->whereIn('name', $names)
            ->pluck('id', 'name');

        // Keys are compared lowercased, and cast first: a numeric name ("2025") comes back as an int key.
        $this->assertAllKnown(
            array_combine(array_map(fn (string $name): string => strtolower($name), $names), $names),
            $found->keys()->map(fn (mixed $name): string => strtolower((string) $name))->all(),
            'order type',
            fn (): array => array_column($this->orderTypes()['order_types'], 'name')
        );

        return $found->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function orderTypesQuery(): Builder
    {
        return OrderTypes::query()
            ->fromApp($this->app)
            ->whereIn('companies_id', [$this->company->getId(), 0])
            ->notDeleted();
    }

    /**
     * Tags are app-wide by name (`CreateTagAction` dedupes on apps_id + name), so a tag is matched on
     * its slug and the company cut happens on the orders side.
     *
     * @param list<string> $names
     *
     * @return int[]
     */
    private function resolveTagIds(array $names): array
    {
        $bySlug = array_combine(array_map(fn (string $name): string => Str::slug($name), $names), $names);

        $found = Tag::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->whereIn('slug', array_keys($bySlug))
            ->pluck('id', 'slug');

        $this->assertAllKnown($bySlug, $found->keys()->all(), 'tag', fn (): array => $this->orderTagsInUse());

        return $found->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @param array<string, string> $requested comparable key => the name as the caller wrote it
     * @param list<string> $found the comparable keys that resolved
     * @param callable(): list<string> $valid
     */
    private function assertAllKnown(
        array $requested,
        array $found,
        string $kind,
        callable $valid
    ): void {
        $unknown = array_values(array_diff_key($requested, array_flip($found)));
        if ($unknown === []) {
            return;
        }

        $validNames = $valid();

        throw new ValidationException(sprintf(
            'Unknown %s: %s. The %ss this company has are: %s. Pick from that list; do not retry a spelling.',
            $kind,
            implode(', ', $unknown),
            $kind,
            $validNames === [] ? '(none)' : implode(', ', $validNames)
        ));
    }

    /**
     * Never `whereHas('tags')` here: the pivot lives on the social connection and the correlated
     * subquery crosses that boundary matching nothing, silently. Read the pivot and feed the ids back.
     *
     * @param int[] $tagIds
     *
     * @return int[]
     */
    private function taggedOrderIds(array $tagIds): array
    {
        return $this->orderTagPivot()
            ->whereIn('tags_entities.tags_id', $tagIds)
            ->distinct()
            ->pluck('tags_entities.entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function orderTagPivot(): QueryBuilder
    {
        return DB::connection('social')
            ->table('tags_entities')
            ->where('tags_entities.taggable_type', new Order()->getMorphClass())
            ->where('tags_entities.is_deleted', 0);
    }

    /**
     * Tag names carried by at least one order of this company. The pivot does not reliably carry the
     * company, so the tagged ids are read first and then cut to this company's orders.
     *
     * @return list<string>
     */
    private function orderTagsInUse(): array
    {
        $tagged = $this->orderTagPivot()
            ->join('tags', 'tags.id', '=', 'tags_entities.tags_id')
            ->where('tags.apps_id', $this->app->getId())
            ->where('tags.is_deleted', 0)
            ->distinct()
            ->get(['tags_entities.entity_id', 'tags.name']);

        if ($tagged->isEmpty()) {
            return [];
        }

        $owned = $tagged->pluck('entity_id')
            ->unique()
            ->chunk(5000)
            ->flatMap(fn ($ids) => $this->baseQuery(OrderReportScope::everything(), null, null)
                ->whereIn('orders.id', $ids->all())
                ->pluck('orders.id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        return $tagged
            ->filter(fn ($row): bool => $owned->has((int) $row->entity_id))
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->unique()
            ->sort()
            ->values()
            ->all();
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
    private function periodExpression(string $interval, string $column): string
    {
        return match ($interval) {
            'day' => "DATE({$column})",
            'week' => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            default => "DATE_FORMAT({$column}, '%Y-%m-01')",
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
