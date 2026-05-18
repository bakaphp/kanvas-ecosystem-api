<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\AgentSwarm;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmDailyCycle;
use Kanvas\Intelligence\Agents\Services\SwarmBudgetService;
use Kanvas\Intelligence\Agents\Services\SwarmCostService;
use Kanvas\Users\Models\Users;

class SwarmMetricsQuery
{
    public function find(mixed $rootValue, array $args): ?AgentSwarm
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return AgentSwarm::query()
            ->where('id', (int) $args['id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->first();
    }

    public function costToday(AgentSwarm $swarm, array $args): float
    {
        // Prefer the materialized rollup on today's cycle row — saves the
        // 3-table JOIN + SUM on every dashboard load. Falls back to live
        // aggregation when the cycle hasn't run yet (early morning, or
        // first day a swarm is in use).
        $today = Carbon::now()->toDateString();
        $cycle = AgentSwarmDailyCycle::query()
            ->where('agent_swarm_id', $swarm->getId())
            ->where('cycle_date', $today)
            ->where('is_deleted', 0)
            ->first();
        if ($cycle !== null && $cycle->cost_usd_today !== null) {
            return (float) $cycle->cost_usd_today;
        }

        return app(SwarmCostService::class)->costToday($swarm);
    }

    public function dailyCycle(AgentSwarm $swarm, array $args): ?AgentSwarmDailyCycle
    {
        $date = isset($args['date']) ? Carbon::parse((string) $args['date']) : Carbon::now();

        return AgentSwarmDailyCycle::query()
            ->where('agent_swarm_id', $swarm->getId())
            ->where('cycle_date', $date->toDateString())
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function budgetSnapshot(AgentSwarm $swarm, array $args): ?array
    {
        return app(SwarmBudgetService::class)->snapshot($swarm);
    }

    /**
     * @return array{reason: string, message: string, severity: string}|null
     */
    public function needsAttention(AgentSwarm $swarm, array $args): ?array
    {
        $snapshot = app(SwarmBudgetService::class)->snapshot($swarm);
        if ($snapshot === null) {
            return null;
        }

        if ($snapshot['hard_stop_threshold_hit']) {
            return [
                'reason' => 'BUDGET_EXHAUSTED',
                'severity' => 'critical',
                'message' => sprintf(
                    'Monthly budget reached 100%%. Spent $%s of $%s — raise the cap or wait for the period to reset.',
                    number_format($snapshot['spent_usd'], 2),
                    number_format((float) ($snapshot['monthly_cost_cap_usd'] ?? 0), 2),
                ),
            ];
        }

        if ($snapshot['warn_threshold_hit']) {
            $pct = $this->highestPctUsed($snapshot);

            return [
                'reason' => 'BUDGET_WARNING',
                'severity' => 'warning',
                'message' => sprintf(
                    'Spend reached %d%% of your monthly budget. %d days left in the period.',
                    $pct,
                    $snapshot['days_left_in_period'],
                ),
            ];
        }

        return null;
    }

    public function activeAgentsCount(AgentSwarm $swarm, array $args): int
    {
        return DB::connection('intelligence')
            ->table('agent_swarm_members as m')
            ->join('agents as a', 'a.id', '=', 'm.agent_id')
            ->where('m.agent_swarm_id', $swarm->getId())
            ->where('m.is_deleted', 0)
            ->where('a.is_active', 1)
            ->where('a.is_deleted', 0)
            ->where('a.awake_state', 'awake')
            ->count();
    }

    public function pendingDecisionsCount(AgentSwarm $swarm, array $args): int
    {
        return DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('swarm_id', $swarm->getId())
            ->where('status', 'awaiting_approval')
            ->where('is_deleted', 0)
            ->count();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function highestPctUsed(array $snapshot): int
    {
        $pcts = [];
        if (! empty($snapshot['monthly_cost_cap_usd']) && (float) $snapshot['monthly_cost_cap_usd'] > 0.0) {
            $pcts[] = (int) round((float) $snapshot['spent_usd'] / (float) $snapshot['monthly_cost_cap_usd'] * 100);
        }
        if (! empty($snapshot['monthly_token_cap']) && (int) $snapshot['monthly_token_cap'] > 0) {
            $pcts[] = (int) round((float) $snapshot['tokens_used'] / (float) $snapshot['monthly_token_cap'] * 100);
        }
        if (! empty($snapshot['monthly_task_cap']) && (int) $snapshot['monthly_task_cap'] > 0) {
            $pcts[] = (int) round((float) $snapshot['tasks_used'] / (float) $snapshot['monthly_task_cap'] * 100);
        }

        return $pcts === [] ? 0 : max($pcts);
    }
}
