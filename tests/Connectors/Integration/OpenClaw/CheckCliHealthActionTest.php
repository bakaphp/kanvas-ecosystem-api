<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Enums\AgentAwakeStateEnum;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

/**
 * The 2-strike state machine itself is covered by the Hermes suite — these tests only confirm
 * the OpenClaw action plugs into the shared BaseCheckHealthAction in both directions.
 */
class CheckCliHealthActionTest extends TestCase
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
            $machine->name = 'OpenClawHealthCheckTest-' . fake()->uuid();
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

        $deployment = new AgentDeployment();
        $deployment->apps_id = $app->getId();
        $deployment->companies_id = $company->getId();
        $deployment->agent_id = $agent->getId();
        $deployment->agent_machine_id = $machine->getId();
        $deployment->system_user = 'agent-' . $agent->slug;
        $deployment->home_directory = '/home/agent-' . $agent->slug;
        $deployment->gateway_port = 21000 + rand(0, 8000);
        $deployment->proxy_port = $deployment->gateway_port + 1;
        $deployment->container_name = 'openclaw-' . $agent->slug;
        $deployment->provider = AgentProviderEnum::OPENCLAW->value;
        $deployment->status = 'running';
        $deployment->saveOrFail();

        return $deployment;
    }

    public function testTwoConsecutiveFailuresFlipOpenClawAgentOffline(): void
    {
        $deployment = $this->makeDeployment();
        /** @var Agent $agent */
        $agent = Agent::getById($deployment->agent_id);
        $agent->awake_state = AgentAwakeStateEnum::AWAKE->value;
        $agent->saveOrFail();

        new CheckCliHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();
        new CheckCliHealthActionStub($deployment, HealthCheckResultEnum::FAILED)->execute();

        $agent->refresh();
        $this->assertSame(AgentAwakeStateEnum::OFFLINE->value, $agent->awake_state);
    }

    public function testSuccessfulProbeRecoversOpenClawAgent(): void
    {
        $deployment = $this->makeDeployment();
        /** @var Agent $agent */
        $agent = Agent::getById($deployment->agent_id);
        $agent->awake_state = AgentAwakeStateEnum::OFFLINE->value;
        $agent->saveOrFail();

        new CheckCliHealthActionStub($deployment, HealthCheckResultEnum::OK)->execute();

        $agent->refresh();
        $this->assertSame(AgentAwakeStateEnum::AWAKE->value, $agent->awake_state);
    }
}
