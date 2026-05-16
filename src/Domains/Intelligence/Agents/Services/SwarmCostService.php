<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;

class SwarmCostService
{
    /**
     * Sum cost_usd of all snapshots whose deployment belongs to an agent
     * that's a current member of this swarm. "Today" = server-time UTC date.
     */
    public function costToday(AgentSwarm $swarm): float
    {
        return $this->costForDate($swarm, Carbon::now()->toDateString());
    }

    public function costForDate(AgentSwarm $swarm, string $date): float
    {
        $sum = DB::connection('intelligence')
            ->table('agent_usage_snapshots as s')
            ->join('agent_deployments as d', 'd.id', '=', 's.agent_deployment_id')
            ->join('agent_swarm_members as m', 'm.agent_id', '=', 'd.agent_id')
            ->where('m.agent_swarm_id', $swarm->getId())
            ->where('m.is_deleted', 0)
            ->where('s.is_deleted', 0)
            ->where('s.snapshot_date', $date)
            ->sum('s.cost_usd');

        return (float) $sum;
    }

    /**
     * Period-to-date cost. Period starts at $periodStart (a Date) and runs
     * up to and including today.
     */
    public function costForPeriod(AgentSwarm $swarm, Carbon $periodStart): float
    {
        $sum = DB::connection('intelligence')
            ->table('agent_usage_snapshots as s')
            ->join('agent_deployments as d', 'd.id', '=', 's.agent_deployment_id')
            ->join('agent_swarm_members as m', 'm.agent_id', '=', 'd.agent_id')
            ->where('m.agent_swarm_id', $swarm->getId())
            ->where('m.is_deleted', 0)
            ->where('s.is_deleted', 0)
            ->whereBetween('s.snapshot_date', [$periodStart->toDateString(), Carbon::now()->toDateString()])
            ->sum('s.cost_usd');

        return (float) $sum;
    }

    /**
     * Total tokens (input + output) spent in the period. Cache tokens
     * excluded — they're priced separately and the "tokens used" budget
     * card tracks raw billable volume, not cache traffic.
     */
    public function tokensForPeriod(AgentSwarm $swarm, Carbon $periodStart): int
    {
        $sum = DB::connection('intelligence')
            ->table('agent_usage_snapshots as s')
            ->join('agent_deployments as d', 'd.id', '=', 's.agent_deployment_id')
            ->join('agent_swarm_members as m', 'm.agent_id', '=', 'd.agent_id')
            ->where('m.agent_swarm_id', $swarm->getId())
            ->where('m.is_deleted', 0)
            ->where('s.is_deleted', 0)
            ->whereBetween('s.snapshot_date', [$periodStart->toDateString(), Carbon::now()->toDateString()])
            ->sum(DB::raw('s.input_tokens + s.output_tokens'));

        return (int) $sum;
    }
}
