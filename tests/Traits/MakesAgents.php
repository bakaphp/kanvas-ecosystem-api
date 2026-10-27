<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SetMyEmailSignatureTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
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

    /**
     * An internal assistant in $company with no mailbox, so it sends over SMTP. $signs grants it
     * set_my_email_signature — the grant that makes its outbound email carry the owner's signature.
     */
    protected function makeSigningAgent(Companies $company, bool $signs = true): Agent
    {
        $app = app(Apps::class);
        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => SystemUserAgent::class]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId()]);

        if ($signs) {
            $tool = Tool::create([
                'apps_id' => $app->getId(),
                'name' => 'set_my_email_signature_' . uniqid(),
                'description' => 'Fixture tool.',
                'tool_type' => 'system',
                'handler' => SetMyEmailSignatureTool::class,
                'frameworks' => ['neuron'],
                'version' => '1.0.0',
                'is_active' => 1,
                'is_deleted' => 0,
            ]);
            $agent->selectedTools()->attach($tool->getKey());
        }

        return $agent;
    }
}
