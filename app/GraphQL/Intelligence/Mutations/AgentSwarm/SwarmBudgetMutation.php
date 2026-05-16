<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\AgentSwarm;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmBudget;
use Kanvas\Intelligence\Agents\Services\SwarmBudgetService;
use Kanvas\Users\Models\Users;

class SwarmBudgetMutation
{
    /**
     * Upsert the monthly budget for a swarm. Validates that at least one
     * cap is set so the row isn't empty, and that the warn/reset values
     * stay in safe ranges.
     *
     * @return array<string, mixed>
     */
    public function set(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $request['input'];

        /** @var AgentSwarm $swarm */
        $swarm = AgentSwarm::getByIdFromCompanyApp((int) $request['swarm_id'], $company, $app);

        $costCap = isset($input['monthly_cost_cap_usd']) ? (float) $input['monthly_cost_cap_usd'] : null;
        $tokenCap = isset($input['monthly_token_cap']) ? (int) $input['monthly_token_cap'] : null;
        $taskCap = isset($input['monthly_task_cap']) ? (int) $input['monthly_task_cap'] : null;

        if ($costCap === null && $tokenCap === null && $taskCap === null) {
            throw new ValidationException('Budget must have at least one cap (cost, token, or task).');
        }

        $warnAtPct = isset($input['warn_at_pct']) ? (int) $input['warn_at_pct'] : 80;
        if ($warnAtPct < 1 || $warnAtPct > 99) {
            throw new ValidationException('warn_at_pct must be between 1 and 99.');
        }

        $resetsOn = isset($input['period_resets_on']) ? (int) $input['period_resets_on'] : 1;
        if ($resetsOn < 1 || $resetsOn > 28) {
            throw new ValidationException('period_resets_on must be between 1 and 28.');
        }

        AgentSwarmBudget::updateOrCreate(
            [
                'agent_swarm_id' => $swarm->getId(),
                'period' => 'monthly',
                'is_deleted' => 0,
            ],
            [
                'apps_id' => $swarm->apps_id,
                'companies_id' => $swarm->companies_id,
                'monthly_cost_cap_usd' => $costCap,
                'monthly_token_cap' => $tokenCap,
                'monthly_task_cap' => $taskCap,
                'warn_at_pct' => $warnAtPct,
                'hard_stop_at_cap' => (bool) ($input['hard_stop_at_cap'] ?? false),
                'period_resets_on' => $resetsOn,
                'is_active' => true,
            ],
        );

        $snapshot = app(SwarmBudgetService::class)->snapshot($swarm);
        if ($snapshot === null) {
            throw new ValidationException('Budget written but snapshot resolution failed — likely a tenant scoping bug.');
        }

        return $snapshot;
    }
}
