<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Services\AgentAwakeStateWriterService;
use Kanvas\Intelligence\Agents\Enums\AgentAwakeStateEnum;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

/**
 * Exercises the in-process branch of `kanvas:agent-runtime-check-health` —
 * agents with provider=neuron/laravel/adk have no AgentDeployment; their awake_state
 * is reconciled from `is_active` directly via the shared AgentAwakeStateWriterService.
 */
class InProcessAgentAwakeStateReconcileTest extends TestCase
{
    private function makeInProcessAgent(string $provider, bool $isActive, string $startingAwakeState): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->create([
            'provider' => $provider,
        ]);

        $agent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
            'agent_type_id' => $agentType->getId(),
            'is_active' => $isActive,
        ]);
        $agent->awake_state = $startingAwakeState;
        $agent->saveOrFail();

        return $agent;
    }

    public function testTransitionerFlipsAwakeToOfflineForInactiveLaravelAgent(): void
    {
        $agent = $this->makeInProcessAgent(
            AgentProviderEnum::LARAVEL->value,
            false,
            AgentAwakeStateEnum::AWAKE->value,
        );

        $changed = new AgentAwakeStateWriterService()->write(
            $agent,
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            AgentAwakeStateEnum::OFFLINE,
            EventStatusEnum::ERROR,
        );

        $this->assertTrue($changed);
        $agent->refresh();
        $this->assertSame(AgentAwakeStateEnum::OFFLINE->value, $agent->awake_state);

        $event = Event::query()
            ->where('event_type', 'agent.runtime.health.observed')
            ->where('source_entity_id', $agent->getId())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AgentProviderEnum::LARAVEL->value, $event->payload['provider'] ?? null);
        $this->assertNull($event->payload['deployment_id'] ?? null, 'in-process agents have no deployment');
    }

    public function testTransitionerNoOpsWhenAlreadyAtTargetState(): void
    {
        $agent = $this->makeInProcessAgent(
            AgentProviderEnum::NEURON->value,
            true,
            AgentAwakeStateEnum::AWAKE->value,
        );

        $eventsBefore = Event::query()
            ->where('source_entity_id', $agent->getId())
            ->count();

        $changed = new AgentAwakeStateWriterService()->write(
            $agent,
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            AgentAwakeStateEnum::AWAKE,
            EventStatusEnum::SUCCESS,
        );

        $this->assertFalse($changed);

        $eventsAfter = Event::query()
            ->where('source_entity_id', $agent->getId())
            ->count();
        $this->assertSame($eventsBefore, $eventsAfter, 'no ledger write on no-op');
    }

    public function testTransitionerSkipsSleepingAgent(): void
    {
        $agent = $this->makeInProcessAgent(
            AgentProviderEnum::LARAVEL->value,
            true,
            AgentAwakeStateEnum::SLEEPING->value,
        );

        $changed = new AgentAwakeStateWriterService()->write(
            $agent,
            app(Apps::class),
            auth()->user()->getCurrentCompany(),
            AgentAwakeStateEnum::AWAKE,
            EventStatusEnum::SUCCESS,
        );

        $this->assertFalse($changed, 'sleep cycle owns awake_state during sleep');
        $agent->refresh();
        $this->assertSame(AgentAwakeStateEnum::SLEEPING->value, $agent->awake_state);
    }
}
