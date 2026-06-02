<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use Kanvas\Users\Models\Users;

class CapabilityQuery
{
    /**
     * @return Collection<int, AgentSkill>
     */
    public function agentSkills(mixed $rootValue, array $args): Collection
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        Agent::getByIdFromCompanyApp((int) $args['agent_id'], $company, $app);

        return AgentSkill::query()
            ->with('skill')
            ->where('agent_id', (int) $args['agent_id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->active()
            ->notExpired()
            ->get();
    }

    /**
     * Returns tools for an agent via its agent_type.
     *
     * @return Collection<int, Tool>
     */
    public function agentTools(mixed $rootValue, array $args): Collection
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $args['agent_id'], $company, $app);

        // Union: tools attached to this agent's type (template defaults) +
        // tools individually granted via setNervousSystemAgentTool, minus
        // anything the agent has explicitly revoked.
        $effective = [];
        $revoked = [];

        if ($agent->agent_type_id > 0) {
            $typeToolIds = DB::connection('intelligence')
                ->table('nervous_system_tool_agent_types')
                ->where('agent_type_id', $agent->agent_type_id)
                ->pluck('tool_id')
                ->all();
            foreach ($typeToolIds as $id) {
                $effective[(int) $id] = true;
            }
        }

        // Pick the latest row per (agent_id, tool_id) so an older soft-deleted
        // row from a prior off-cycle doesn't poison a later re-grant. The
        // write side ought to reactivate in place (see GrantToolToAgentAction
        // withTrashed lookup), but if any historical duplicate slipped
        // through, the most recent row is the source of truth.
        $grantRows = DB::connection('intelligence')
            ->table('nervous_system_agent_tools')
            ->where('agent_id', $agent->getId())
            ->orderBy('id')
            ->get(['tool_id', 'is_active', 'is_deleted']);
        $latestByTool = [];
        foreach ($grantRows as $row) {
            $latestByTool[(int) $row->tool_id] = $row;
        }
        foreach ($latestByTool as $toolId => $row) {
            if (! $row->is_active || $row->is_deleted) {
                $revoked[$toolId] = true;
            } else {
                $effective[$toolId] = true;
            }
        }

        $finalIds = array_diff(array_keys($effective), array_keys($revoked));
        if ($finalIds === []) {
            return collect();
        }

        return Tool::query()
            ->whereIn('id', $finalIds)
            ->fromAppOrGlobal($app)
            ->active()
            ->get();
    }

    /**
     * @return array{skills: \Illuminate\Support\Collection<int, \Kanvas\NervousSystem\Capability\Models\Skill>, tools: \Illuminate\Support\Collection<int, \Kanvas\NervousSystem\Capability\Models\Tool>}
     */
    public function agentCapabilities(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $args['agent_id'], $company, $app);

        return new CapabilityProvider()->getActiveCapabilities(
            $agent,
            isset($args['framework']) ? (string) $args['framework'] : null,
        );
    }
}
