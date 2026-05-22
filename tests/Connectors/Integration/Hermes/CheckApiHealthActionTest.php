<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Hermes;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentRuntimeStateEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

/**
 * Exercises the 2-strike state machine in {@see CheckApiHealthAction} without doing any real
 * SSH. Subclasses the action and overrides probe() to return canned values, so the test only
 * drives the state-machine path and the ledger emission.
 */
class CheckApiHealthActionTest extends TestCase
{
    private function makeDeployment(): AgentDeployment
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $machine = AgentMachine::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->first();

        if (! $machine) {
            $machine = new AgentMachine();
            $machine->apps_id = $app->getId();
            $machine->companies_id = $company->getId();
            $machine->name = 'HermesHealthCheckTest-' . fake()->uuid();
            $machine->host = '127.0.0.1';
            $machine->ssh_port = 22;
            $machine->ssh_user = 'test';
            $machine->ssh_private_key = 'test-key';
            $machine->port_range_start = 20000;
            $machine->port_range_end = 30000;
            $machine->max_agents = 100;
            $machine->is_active = true;
            $machine->saveOrFail();
        }

        $agent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
        ]);
        $agent->set(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value, 'test-token');

        $deployment = new AgentDeployment();
        $deployment->apps_id = $app->getId();
        $deployment->companies_id = $company->getId();
        $deployment->agent_id = $agent->getId();
        $deployment->agent_machine_id = $machine->getId();
        $deployment->system_user = 'agent-' . $agent->slug;
        $deployment->home_directory = '/home/agent-' . $agent->slug;
        $deployment->gateway_port = 21000 + rand(0, 8000);
        $deployment->proxy_port = $deployment->gateway_port + 1;
        $deployment->container_name = 'hermes-' . $agent->slug;
        $deployment->provider = 'hermes';
        $deployment->status = 'running';
        $deployment->saveOrFail();

        return $deployment;
    }

    private function refreshAgent(AgentDeployment $deployment): Agent
    {
        /** @var Agent $agent */
        $agent = Agent::getById($deployment->agent_id);

        return $agent;
    }

    public function testFirstFailedCheckDoesNotFlipAwakeState(): void
    {
        $deployment = $this->makeDeployment();
        $agent = $this->refreshAgent($deployment);
        $agent->awake_state = 'awake';
        $agent->saveOrFail();

        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();

        $agent->refresh();
        $this->assertSame('awake', $agent->awake_state, 'first failed check is a tolerated blip');
        $this->assertSame(
            'failed',
            $agent->get(AgentRuntimeStateEnum::LAST_HEALTH_STATUS->value),
            'last-check status must persist for the next tick to see "previous=failed"',
        );
    }

    public function testTwoConsecutiveFailuresFlipToOffline(): void
    {
        $deployment = $this->makeDeployment();
        $agent = $this->refreshAgent($deployment);
        $agent->awake_state = 'awake';
        $agent->saveOrFail();

        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();
        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();

        $agent->refresh();
        $this->assertSame('offline', $agent->awake_state);

        $event = Event::query()
            ->where('event_type', 'agent.runtime.health.observed')
            ->where('source_entity_type', Agent::class)
            ->where('source_entity_id', $agent->getId())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'ledger event must be emitted on the flip');
        $this->assertSame('error', $event->status);
        $this->assertSame('offline', $event->payload['awake_state'] ?? null);
    }

    public function testSuccessWhileOfflineFlipsBackToAwake(): void
    {
        $deployment = $this->makeDeployment();
        $agent = $this->refreshAgent($deployment);
        $agent->awake_state = 'offline';
        $agent->saveOrFail();

        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::OK)->execute();

        $agent->refresh();
        $this->assertSame('awake', $agent->awake_state);

        $event = Event::query()
            ->where('event_type', 'agent.runtime.health.observed')
            ->where('source_entity_type', Agent::class)
            ->where('source_entity_id', $agent->getId())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('success', $event->status);
        $this->assertSame('awake', $event->payload['awake_state'] ?? null);
    }

    public function testSleepingAgentIsNeverTouched(): void
    {
        $deployment = $this->makeDeployment();
        $agent = $this->refreshAgent($deployment);
        $agent->awake_state = 'sleeping';
        $agent->saveOrFail();

        // Drive both directions — two failures then a success.
        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();
        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();
        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::OK)->execute();

        $agent->refresh();
        $this->assertSame('sleeping', $agent->awake_state, 'sleep cycle owns awake_state during sleep');
    }

    public function testSuccessWhileAwakeDoesNotEmitOrFlip(): void
    {
        $deployment = $this->makeDeployment();
        $agent = $this->refreshAgent($deployment);
        $agent->awake_state = 'awake';
        $agent->saveOrFail();

        $eventsBefore = Event::query()
            ->where('event_type', 'agent.runtime.health.observed')
            ->where('source_entity_id', $agent->getId())
            ->count();

        new CheckApiHealthActionStub($deployment, HealthCheckResultEnum::OK)->execute();

        $agent->refresh();
        $this->assertSame('awake', $agent->awake_state);

        $eventsAfter = Event::query()
            ->where('event_type', 'agent.runtime.health.observed')
            ->where('source_entity_id', $agent->getId())
            ->count();
        $this->assertSame($eventsBefore, $eventsAfter, 'no event on steady-state success');
    }
}
