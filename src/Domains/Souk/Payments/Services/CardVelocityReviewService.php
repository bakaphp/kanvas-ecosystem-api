<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\UsersAssociatedApps;

class CardVelocityReviewService
{
    private const int STUCK_3DS_MINUTES = 30;

    private const int TOP_PROCESSOR_CODES = 5;

    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
    ) {
    }

    public function blocked(
        Carbon $since,
        Carbon $until,
        int $limit,
        ?array $orderTypeIds = null,
    ): array {
        $logs = $this->blockedLogsQuery($since, $until, $orderTypeIds)
            ->get(['users_id', 'payments_id', 'payable_id', 'metadata', 'created_at']);

        if ($logs->isEmpty()) {
            return [];
        }

        $orderTypeNamesByOrderId = Order::query()
            ->whereIn('id', $logs->pluck('payable_id')->filter()->unique())
            ->with('orderType:id,name')
            ->get(['id', 'order_types_id'])
            ->mapWithKeys(fn (Order $order): array => [$order->getId() => $order->orderType?->name])
            ->all();

        $paymentsById = Payments::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereIn('id', $logs->pluck('payments_id')->filter()->unique())
            ->get(['id', 'payment_method_brand', 'payment_method_last_four'])
            ->keyBy('id');

        $rows = $logs
            ->groupBy('users_id')
            ->filter(fn ($_, $usersId): bool => (int) $usersId > 0)
            ->map(function (Collection $userLogs) use ($orderTypeNamesByOrderId, $paymentsById): array {
                $cards = $userLogs
                    ->map(fn (PaymentLogs $log) => $paymentsById->get($log->payments_id))
                    ->filter()
                    ->map(fn (Payments $payment) => Payments::cardKey($payment->payment_method_brand, $payment->payment_method_last_four))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $orderTypes = $userLogs
                    ->map(fn (PaymentLogs $log) => $orderTypeNamesByOrderId[$log->payable_id] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'block_count' => $userLogs->count(),
                    'order_types' => $orderTypes,
                    'distinct_cards' => $cards,
                    'banned_at_block' => $userLogs->contains(fn (PaymentLogs $log): bool => (bool) ($log->metadata['banned'] ?? false)),
                    'first_blocked_at' => (string) $userLogs->min('created_at'),
                    'last_blocked_at' => (string) $userLogs->max('created_at'),
                ];
            })
            ->sortByDesc('block_count')
            ->take($limit);

        $rows = $this->withPaidStats(
            $rows,
            $since,
            $until,
            $orderTypeIds,
        );

        return $this->withUserContext($rows->all());
    }

    public function atRisk(
        Carbon $since,
        Carbon $until,
        int $minDeclines,
        float $maxPaidRatio,
        int $limit,
        ?array $orderTypeIds = null,
    ): array {
        $excludedUserIds = $this->blockedUserIds($since, $until);

        $counts = $this->failedAttemptsQuery($since, $until, $orderTypeIds)
            ->when($excludedUserIds !== [], fn (Builder $query) => $query->whereNotIn('users_id', $excludedUserIds))
            ->selectRaw('users_id, COUNT(*) as failed_count')
            ->groupBy('users_id')
            ->having('failed_count', '>=', $minDeclines)
            ->pluck('failed_count', 'users_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $rows = $this->withPaidStats(
            $counts->map(fn (int $failedCount): array => ['failed_count' => $failedCount]),
            $since,
            $until,
            $orderTypeIds,
        )
            ->map(fn (array $row): array => [...$row, 'paid_ratio' => round($this->paidRatio($row), 4)])
            ->filter(fn (array $row): bool => $row['paid_ratio'] <= $maxPaidRatio)
            ->sortByDesc('failed_count')
            ->take($limit);

        if ($rows->isEmpty()) {
            return [];
        }

        $rows = $this->withDistinctCards(
            $rows,
            $since,
            $until,
            $orderTypeIds,
        );
        $rows = $this->withProcessorCodes(
            $rows,
            $since,
            $until,
            $orderTypeIds,
        );

        return $this->withUserContext($rows->all());
    }

    private function blockedLogsQuery(Carbon $since, Carbon $until, ?array $orderTypeIds): Builder
    {
        return PaymentLogs::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('event_type', Payments::CARD_VELOCITY_BLOCKED_EVENT)
            ->whereBetween('created_at', [$since, $until])
            ->tap(fn (Builder $query) => $this->whereOrderTypes($query, $orderTypeIds));
    }

    private function whereOrderTypes(Builder $query, ?array $orderTypeIds): Builder
    {
        if ($orderTypeIds === null) {
            return $query;
        }

        return $query
            ->where('payable_type', Order::class)
            ->whereIn('payable_id', Order::query()->select('id')->whereIn('order_types_id', $orderTypeIds));
    }

    private function blockedUserIds(Carbon $since, Carbon $until): array
    {
        return $this->blockedLogsQuery($since, $until, null)
            ->whereNotNull('users_id')
            ->distinct()
            ->pluck('users_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function failedAttemptsQuery(Carbon $since, Carbon $until, ?array $orderTypeIds): Builder
    {
        $stuckCutoff = now()->subMinutes(self::STUCK_3DS_MINUTES);

        return Payments::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereBetween('created_at', [$since, $until])
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [PaymentStatusEnum::FAILED->value, PaymentStatusEnum::CANCELLED->value])
                ->orWhere(fn (Builder $stuck) => $stuck
                    ->whereIn('status', [PaymentStatusEnum::WAITING_DEVICE_DATA->value, PaymentStatusEnum::PENDING_AUTHORIZATION->value])
                    ->where('created_at', '<', $stuckCutoff)))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->select(DB::raw(1))
                ->from('payment_logs')
                ->whereColumn('payment_logs.payments_id', 'payments.id')
                ->where('payment_logs.event_type', Payments::CARD_VELOCITY_BLOCKED_EVENT))
            ->tap(fn (Builder $query) => $this->whereOrderTypes($query, $orderTypeIds));
    }

    private function paidRatio(array $row): float
    {
        $attempts = $row['paid_count'] + $row['failed_count'];

        return $attempts > 0 ? $row['paid_count'] / $attempts : 0.0;
    }

    private function withPaidStats(
        Collection $rows,
        Carbon $since,
        Carbon $until,
        ?array $orderTypeIds,
    ): Collection {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $paidStats = Payments::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->whereIn('users_id', $rows->keys()->all())
            ->whereBetween('created_at', [$since, $until])
            ->tap(fn (Builder $query) => $this->whereOrderTypes($query, $orderTypeIds))
            ->selectRaw('users_id, COUNT(*) as paid_count, COALESCE(SUM(amount), 0) as paid_amount')
            ->groupBy('users_id')
            ->get()
            ->keyBy('users_id');

        return $rows->map(fn (array $row, $usersId): array => [
            ...$row,
            'paid_count' => (int) ($paidStats[$usersId]->paid_count ?? 0),
            'paid_amount' => (float) ($paidStats[$usersId]->paid_amount ?? 0),
        ]);
    }

    private function withDistinctCards(
        Collection $rows,
        Carbon $since,
        Carbon $until,
        ?array $orderTypeIds,
    ): Collection {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $cards = $this->failedAttemptsQuery($since, $until, $orderTypeIds)
            ->whereIn('users_id', $rows->keys()->all())
            ->get(['users_id', 'payment_method_brand', 'payment_method_last_four'])
            ->groupBy('users_id');

        return $rows->map(fn (array $row, $usersId): array => [
            ...$row,
            'distinct_cards' => $cards->get($usersId, collect())
                ->map(fn (Payments $payment) => Payments::cardKey($payment->payment_method_brand, $payment->payment_method_last_four))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    private function withProcessorCodes(
        Collection $rows,
        Carbon $since,
        Carbon $until,
        ?array $orderTypeIds,
    ): Collection {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $codes = PaymentLogs::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereIn('users_id', $rows->keys()->all())
            ->whereBetween('created_at', [$since, $until])
            ->tap(fn (Builder $query) => $this->whereOrderTypes($query, $orderTypeIds))
            ->whereNotNull('processor_response_code')
            ->selectRaw('users_id, processor_response_code, COUNT(*) as occurrences')
            ->groupBy('users_id', 'processor_response_code')
            ->orderByDesc('occurrences')
            ->get()
            ->groupBy('users_id');

        return $rows->map(function (array $row, $usersId) use ($codes): array {
            $userCodes = $codes->get($usersId, collect())
                ->take(self::TOP_PROCESSOR_CODES)
                ->mapWithKeys(fn ($code): array => [(string) $code->processor_response_code => (int) $code->occurrences])
                ->all();

            return [...$row, 'processor_response_codes' => $userCodes];
        });
    }

    private function withUserContext(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $userIds = array_map('intval', array_keys($rows));
        $profiles = UsersAssociatedApps::profilesForApp($this->app, $userIds);
        $isCorporate = filter_var($this->company->get('is_corporate'), FILTER_VALIDATE_BOOLEAN);

        $result = [];
        foreach ($rows as $usersId => $row) {
            $usersId = (int) $usersId;

            $result[] = [
                'user' => UsersAssociatedApps::reviewPayload($usersId, $profiles->get($usersId)),
                'is_corporate' => $isCorporate,
                ...$row,
            ];
        }

        return $result;
    }
}
