<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentCostService;

class AgentUsageQuery
{
    /**
     * Single-value monthly consumption for one agent — tokens + USD cost for
     * the current calendar month. Resolves the `monthly_usage` field on AgentAi.
     *
     * @return array{period_start: string, tokens: int, cost_usd: float}
     */
    public function monthlyUsage(Agent $agent, array $args): array
    {
        return new AgentCostService()->usageForMonth($agent);
    }
}
