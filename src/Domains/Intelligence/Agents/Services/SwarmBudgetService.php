<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmBudget;

class SwarmBudgetService
{
    public function __construct(
        protected readonly SwarmCostService $cost,
    ) {
    }

    /**
     * Resolve the active monthly budget for this swarm (or null).
     */
    public function currentBudget(AgentSwarm $swarm): ?AgentSwarmBudget
    {
        return AgentSwarmBudget::query()
            ->where('agent_swarm_id', $swarm->getId())
            ->where('period', 'monthly')
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * Returns the data the dashboard's monthly-budget panel needs. If the
     * swarm has no budget set, returns null — frontend shows the "Set
     * budget" CTA.
     *
     * @return array{
     *   id: int,
     *   monthly_cost_cap_usd: float|null,
     *   monthly_token_cap: int|null,
     *   monthly_task_cap: int|null,
     *   spent_usd: float,
     *   remaining_usd: float|null,
     *   tokens_used: int,
     *   tasks_used: int,
     *   days_left_in_period: int,
     *   period_resets_on: string,
     *   warn_at_pct: int,
     *   hard_stop_at_cap: bool,
     *   warn_threshold_hit: bool,
     *   hard_stop_threshold_hit: bool,
     * }|null
     */
    public function snapshot(AgentSwarm $swarm): ?array
    {
        $budget = $this->currentBudget($swarm);
        if ($budget === null) {
            return null;
        }

        $periodStart = $this->periodStartFor($budget, Carbon::now());
        $periodEnd = $this->periodEndFor($periodStart);

        $spent = $this->cost->costForPeriod($swarm, $periodStart);
        $tokens = $this->cost->tokensForPeriod($swarm, $periodStart);
        $tasks = $this->tasksUsedSince($swarm, $periodStart);

        $costCap = $budget->monthly_cost_cap_usd !== null ? (float) $budget->monthly_cost_cap_usd : null;
        $tokenCap = $budget->monthly_token_cap;
        $taskCap = $budget->monthly_task_cap;

        $warnHit = $this->thresholdHit($spent, $tokens, $tasks, $costCap, $tokenCap, $taskCap, $budget->warn_at_pct);
        $hardHit = $this->thresholdHit($spent, $tokens, $tasks, $costCap, $tokenCap, $taskCap, 100);

        return [
            'id' => (int) $budget->getId(),
            'monthly_cost_cap_usd' => $costCap,
            'monthly_token_cap' => $tokenCap,
            'monthly_task_cap' => $taskCap,
            'spent_usd' => round($spent, 2),
            'remaining_usd' => $costCap !== null ? round(max(0.0, $costCap - $spent), 2) : null,
            'tokens_used' => $tokens,
            'tasks_used' => $tasks,
            'days_left_in_period' => (int) max(0, Carbon::now()->startOfDay()->diffInDays($periodEnd->startOfDay(), false)),
            'period_resets_on' => $periodEnd->toDateString(),
            'warn_at_pct' => $budget->warn_at_pct,
            'hard_stop_at_cap' => $budget->hard_stop_at_cap,
            'warn_threshold_hit' => $warnHit,
            'hard_stop_threshold_hit' => $hardHit,
        ];
    }

    /**
     * Tasks = nervous_system_plans tied to this swarm, created in-period,
     * not cancelled. v1 treats each plan as one task (matches the dashboard
     * "47 of 200 tasks" card).
     */
    public function tasksUsedSince(AgentSwarm $swarm, Carbon $periodStart): int
    {
        return DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('swarm_id', $swarm->getId())
            ->where('is_deleted', 0)
            ->where('created_at', '>=', $periodStart->toDateTimeString())
            ->count();
    }

    public function periodStartFor(AgentSwarmBudget $budget, Carbon $now): Carbon
    {
        $resetDay = max(1, min(28, $budget->period_resets_on));
        $candidate = $now->copy()->setDay($resetDay)->startOfDay();
        if ($candidate->gt($now)) {
            $candidate->subMonth();
        }

        return $candidate;
    }

    public function periodEndFor(Carbon $periodStart): Carbon
    {
        return $periodStart->copy()->addMonth();
    }

    private function thresholdHit(
        float $spent,
        int $tokens,
        int $tasks,
        ?float $costCap,
        ?int $tokenCap,
        ?int $taskCap,
        int $pct,
    ): bool {
        $threshold = $pct / 100.0;
        if ($costCap !== null && $costCap > 0 && $spent / $costCap >= $threshold) {
            return true;
        }
        if ($tokenCap !== null && $tokenCap > 0 && (float) $tokens / (float) $tokenCap >= $threshold) {
            return true;
        }
        if ($taskCap !== null && $taskCap > 0 && (float) $tasks / (float) $taskCap >= $threshold) {
            return true;
        }

        return false;
    }
}
