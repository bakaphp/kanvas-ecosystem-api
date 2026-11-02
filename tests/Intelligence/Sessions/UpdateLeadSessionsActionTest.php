<?php

declare(strict_types=1);

namespace Tests\Intelligence\Sessions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Sessions\Actions\UpdateLeadSessionsAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Tests\Stubs\Intelligence\ReceptionistNeuronAgentStub;
use Tests\TestCase;

final class UpdateLeadSessionsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function testNeuronSessionIsNotPushedToAdk(): void
    {
        Exceptions::fake();
        $lead = $this->leadWithSession(ReceptionistNeuronAgentStub::class);

        new UpdateLeadSessionsAction($lead)->execute();

        Exceptions::assertNothingReported();
    }

    public function testOnlyAdkHandlersCountAsAdk(): void
    {
        $this->assertTrue($this->agentWithHandler(ADKAgent::class)->isAdk());
        $this->assertFalse($this->agentWithHandler(ReceptionistNeuronAgentStub::class)->isAdk());
    }

    private function agentWithHandler(string $handler): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create([
            'provider' => 'neuron',
            'handler' => $handler,
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => auth()->user()->getId(),
            ]);
    }

    private function leadWithSession(string $handler): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->agentWithHandler($handler);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $uuid = 'lead-session-' . uniqid();

        Session::create([
            'uuid' => $uuid,
            'canal_id' => $uuid,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agents_id' => $agent->getId(),
            'entity_namespace' => Lead::class,
            'entity_id' => $lead->getId(),
            'user' => ['name' => 'Guest', 'id' => auth()->user()->getId(), 'email' => null],
            'content' => [],
        ]);

        return $lead;
    }
}
