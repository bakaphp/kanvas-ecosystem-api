<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\System\ConverseWithSystemAgentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Services\UserAgentChannelService;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SystemUserAgentStub;
use Tests\TestCase;

class ConverseWithSystemAgentActionTest extends TestCase
{
    private function makeStubAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => SystemUserAgentStub::class,
            ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => auth()->user()->getId(),
            ]);
    }

    public function testFunnelReturnsAgentReply(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $agent = $this->makeStubAgent();

        $reply = new ConverseWithSystemAgentAction(
            agent: $agent,
            human: $user,
            message: 'hello system agent',
        )->execute();

        $this->assertStringContainsString('Hola Sistema', $reply);
    }

    public function testSessionIsDurableAcrossTurns(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = $this->makeStubAgent();

        $service = new UserAgentChannelService();

        $first = $service->resolveSession(
            human: $user,
            agent: $agent,
            app: $app,
            company: $company,
            entity: $user,
        );
        $second = $service->resolveSession(
            human: $user,
            agent: $agent,
            app: $app,
            company: $company,
            entity: $user,
        );

        $this->assertSame($first->getId(), $second->getId(), 'Same human + agent reuses one durable session');
        $this->assertSame(Users::class, $first->entity_namespace);
        $this->assertNotNull($first->channel_id);
    }
}
