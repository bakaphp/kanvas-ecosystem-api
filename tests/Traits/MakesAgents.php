<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;

/**
 * An agent in the test app and the user's current company, owned by that user. MakesPlans::makeAgent
 * gives the agent a dedicated user instead, which is what the plan tests need; these tests need the
 * acting human to own it.
 */
trait MakesAgents
{
    protected function makeAgentFor(Users $user, ?AgentType $type = null): Agent
    {
        $app = app(Apps::class);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(array_filter([
                'user_id' => $user->getId(),
                'agent_type_id' => $type?->getId(),
            ]));
    }
}
