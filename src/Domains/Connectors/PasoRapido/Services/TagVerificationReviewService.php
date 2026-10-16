<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Activities\Models\Activity;
use Kanvas\Users\Models\UsersAssociatedApps;

class TagVerificationReviewService
{
    private const string TAG_EXPR = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tag')), 'null')";

    private const string IP_EXPR = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.ip')), 'null')";

    private const string REASON_EXPR = "JSON_UNQUOTE(JSON_EXTRACT(properties, '$.reason'))";

    private const string IS_CORPORATE_EXPR = "MAX(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(properties, '$.is_corporate')) = 'true' THEN 1 ELSE 0 END)";

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    public function byUser(Carbon $since, Carbon $until, int $limit): array
    {
        $totals = $this->logsQuery($since, $until, PasoRapidoService::VERIFY_BLOCKED_LOG_DESCRIPTION)
            ->whereNotNull('causer_id')
            ->selectRaw(
                'causer_id, COUNT(*) as total, '
                    . 'COUNT(DISTINCT ' . self::TAG_EXPR . ') as distinct_tags, '
                    . 'COUNT(DISTINCT ' . self::IP_EXPR . ') as distinct_ips, '
                    . self::IS_CORPORATE_EXPR . ' as is_corporate, '
                    . 'MIN(created_at) as first_blocked_at, MAX(created_at) as last_blocked_at'
            )
            ->groupBy('causer_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        if ($totals->isEmpty()) {
            return [];
        }

        $userIds = $totals->pluck('causer_id')->map(fn (mixed $id): int => (int) $id)->all();
        $reasonsByUser = $this->reasonCountsByCauser($since, $until, $userIds);
        $profilesById = UsersAssociatedApps::profilesForApp($this->app, $userIds);

        return $totals->map(fn ($row): array => [
            'user' => UsersAssociatedApps::reviewPayload((int) $row->causer_id, $profilesById->get((int) $row->causer_id)),
            'total' => (int) $row->total,
            'reasons' => $reasonsByUser->get((int) $row->causer_id, []),
            'distinct_tags' => (int) $row->distinct_tags,
            'distinct_ips' => (int) $row->distinct_ips,
            'is_corporate' => (bool) $row->is_corporate,
            'first_blocked_at' => (string) $row->first_blocked_at,
            'last_blocked_at' => (string) $row->last_blocked_at,
        ])->all();
    }

    public function byIp(Carbon $since, Carbon $until, int $limit): array
    {
        $totals = $this->logsQuery($since, $until, PasoRapidoService::VERIFY_BLOCKED_LOG_DESCRIPTION)
            ->whereRaw(self::IP_EXPR . ' IS NOT NULL')
            ->selectRaw(
                self::IP_EXPR . ' as ip, '
                    . 'COUNT(*) as total, '
                    . 'COUNT(DISTINCT causer_id) as distinct_users, '
                    . 'MIN(created_at) as first_blocked_at, MAX(created_at) as last_blocked_at'
            )
            ->groupBy('ip')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        if ($totals->isEmpty()) {
            return [];
        }

        $ips = $totals->pluck('ip')->filter()->values()->all();
        $reasonsByIp = $this->reasonCountsByIp($since, $until, $ips);

        return $totals->map(fn ($row): array => [
            'ip' => $row->ip,
            'total' => (int) $row->total,
            'distinct_users' => (int) $row->distinct_users,
            'reasons' => $reasonsByIp->get($row->ip, []),
            'first_blocked_at' => (string) $row->first_blocked_at,
            'last_blocked_at' => (string) $row->last_blocked_at,
        ])->all();
    }

    public function successCount(Carbon $since, Carbon $until): int
    {
        return $this->logsQuery($since, $until, PasoRapidoService::VERIFY_LOG_DESCRIPTION)->count();
    }

    private function logsQuery(Carbon $since, Carbon $until, string $description): Builder
    {
        return Activity::query()
            ->where('log_name', PasoRapidoService::verifyLogName($this->app))
            ->where('description', $description)
            ->whereBetween('created_at', [$since, $until]);
    }

    private function reasonCountsByCauser(Carbon $since, Carbon $until, array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return $this->logsQuery($since, $until, PasoRapidoService::VERIFY_BLOCKED_LOG_DESCRIPTION)
            ->whereIn('causer_id', $userIds)
            ->selectRaw('causer_id, ' . self::REASON_EXPR . ' as reason, COUNT(*) as total')
            ->groupBy('causer_id', 'reason')
            ->get()
            ->groupBy(fn ($row): int => (int) $row->causer_id)
            ->map(fn (Collection $rows): array => $rows->pluck('total', 'reason')->map(fn ($total): int => (int) $total)->all());
    }

    private function reasonCountsByIp(Carbon $since, Carbon $until, array $ips): Collection
    {
        if ($ips === []) {
            return collect();
        }

        return $this->logsQuery($since, $until, PasoRapidoService::VERIFY_BLOCKED_LOG_DESCRIPTION)
            ->whereIn(DB::raw(self::IP_EXPR), $ips)
            ->selectRaw(self::IP_EXPR . ' as ip, ' . self::REASON_EXPR . ' as reason, COUNT(*) as total')
            ->groupBy('ip', 'reason')
            ->get()
            ->groupBy('ip')
            ->map(fn (Collection $rows): array => $rows->pluck('total', 'reason')->map(fn ($total): int => (int) $total)->all());
    }
}
