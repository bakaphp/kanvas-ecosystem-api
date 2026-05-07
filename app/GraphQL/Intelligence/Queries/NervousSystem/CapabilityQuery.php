<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\NervousSystem;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
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
     * @return Collection<int, AgentTool>
     */
    public function agentTools(mixed $rootValue, array $args): Collection
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        Agent::getByIdFromCompanyApp((int) $args['agent_id'], $company, $app);

        return AgentTool::query()
            ->with('tool')
            ->where('agent_id', (int) $args['agent_id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->active()
            ->notExpired()
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
