<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\Agent;

class AgentCostService
{
    // Snapshots are written by the nightly usage-collection cron, so the
    // monthly rollup is effectively static within a day. Cache it for a few
    // hours to spare every dashboard load the 2-table JOIN + SUM.
    private const int CACHE_TTL_SECONDS = 6 * 60 * 60;

    /**
     * Tokens + cost an agent burned in a calendar month. Aggregates every
     * usage snapshot tied to any of the agent's deployments (an agent can be
     * relaunched, spawning new deployment rows — all of them count).
     *
     * "This month" = the calendar month containing $when (defaults to today),
     * server-time UTC, from the 1st up to and including today.
     *
     * @return array{period_start: string, tokens: int, cost_usd: float}
     */
    public function usageForMonth(Agent $agent, ?Carbon $when = null): array
    {
        $when ??= Carbon::now();
        $periodStart = $when->copy()->startOfMonth()->toDateString();
        $periodEnd = $when->toDateString();

        // Key by agent + the through-date so today's accumulating numbers stay
        // fresh across days while still absorbing repeat reads within the TTL.
        $cacheKey = "agent_monthly_usage:{$agent->getId()}:{$periodEnd}";

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->aggregate($agent, $periodStart, $periodEnd),
        );
    }

    /**
     * @return array{period_start: string, tokens: int, cost_usd: float}
     */
    private function aggregate(Agent $agent, string $periodStart, string $periodEnd): array
    {
        // Keyed on agent_id directly so it sees every backend: container runtimes
        // (OpenClaw/Hermes — snapshots written via collectUsage) AND in-process
        // backends (Neuron/Laravel — snapshots written by the daily rollup).
        $row = DB::connection('intelligence')
            ->table('agent_usage_snapshots as s')
            ->where('s.agent_id', $agent->getId())
            ->where('s.apps_id', $agent->apps_id)
            ->where('s.companies_id', $agent->companies_id)
            ->where('s.is_deleted', 0)
            ->whereBetween('s.snapshot_date', [$periodStart, $periodEnd])
            ->selectRaw('COALESCE(SUM(s.input_tokens + s.output_tokens), 0) as tokens')
            ->selectRaw('COALESCE(SUM(s.cost_usd), 0) as cost_usd')
            ->first();

        return [
            'period_start' => $periodStart,
            'tokens' => (int) ($row->tokens ?? 0),
            'cost_usd' => (float) ($row->cost_usd ?? 0),
        ];
    }
}
