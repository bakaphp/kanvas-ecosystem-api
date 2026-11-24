<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Services;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\MeetingAgentEnum;

/**
 * The agent that works a routed meeting: the company setting when it names a live agent of this
 * company, else the company's first agent of the role's type. Lowest id, so the pick is stable from
 * one meeting to the next.
 */
class MeetingAgentLocatorService
{
    public function resolve(
        MeetingAgentEnum $role,
        Apps $app,
        Companies $company
    ): ?Agent {
        return $this->configured(
            $role,
            $app,
            $company
        ) ?? $this->firstOfType($role, $app, $company);
    }

    /**
     * The setting is a manual override, so it is honored whatever the agent's type.
     */
    public function configured(MeetingAgentEnum $role, Apps $app, Companies $company): ?Agent
    {
        $agentId = (int) ($company->get($role->value) ?? 0);
        if ($agentId <= 0) {
            return null;
        }

        return $this->live($app, $company)
            ->where('id', $agentId)
            ->first();
    }

    public function firstOfType(MeetingAgentEnum $role, Apps $app, Companies $company): ?Agent
    {
        /** @var Agent|null $agent */
        $agent = $this->live($app, $company)
            ->ofHandler($role->handler())
            ->orderBy('id')
            ->first();

        return $agent;
    }

    /**
     * @return Builder<Agent>
     */
    private function live(Apps $app, Companies $company): Builder
    {
        return Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('is_active', true);
    }
}
