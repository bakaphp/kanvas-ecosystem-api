<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connector\Hermes;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Jobs\MigrateFromOpenClawJob;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use RuntimeException;
use Tests\TestCase;

class HermesMigrateFromOpenClawTest extends TestCase
{
    private function createTestMachine(): AgentMachine
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $machine = AgentMachine::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->first();

        if (! $machine) {
            $machine = new AgentMachine();
            $machine->apps_id = $app->getId();
            $machine->companies_id = $company->getId();
            $machine->name = 'HermesTest-' . fake()->uuid();
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

        return $machine;
    }

    private function createTestDeployment(
        AgentMachine $machine,
        string $status = 'running',
        ?Agent $agent = null,
    ): AgentDeployment {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if ($agent === null) {
            $agent = Agent::where('apps_id', $app->getId())
                ->where('is_deleted', 0)
                ->first();
        }

        if (! $agent) {
            $agent = Agent::factory()->create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'user_id' => $user->getId(),
            ]);
        }

        $deployment = AgentDeployment::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'agent_id' => $agent->getId(),
                'agent_machine_id' => $machine->getId(),
            ],
            [
                'system_user' => 'agent-' . $agent->slug,
                'home_directory' => '/home/agent-' . $agent->slug,
                'gateway_port' => 20000,
                'proxy_port' => 20001,
                'container_name' => 'openclaw-' . $agent->slug,
                'status' => $status,
            ],
        );

        $deployment->status = $status;
        $deployment->saveOrFail();

        return $deployment;
    }

    public function testHermesMigrateFromOpenclawDispatchesJob(): void
    {
        Queue::fake();

        $machine = $this->createTestMachine();
        $sourceDeployment = $this->createTestDeployment($machine);

        $response = $this->graphQL('
            mutation($input: MigrateAgentToProviderInput!) {
                agentRuntimeMigrateAgentToProvider(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'source_deployment_id' => (string) $sourceDeployment->getId(),
                'destination_machine_id' => (string) $machine->getId(),
                'target_provider' => 'HERMES',
            ],
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'agentRuntimeMigrateAgentToProvider' => [
                    'id' => (string) $sourceDeployment->getId(),
                ],
            ],
        ]);

        Queue::assertPushed(MigrateFromOpenClawJob::class);
    }

    public function testHermesMigrateFromOpenclawRequiresValidSourceDeployment(): void
    {
        $response = $this->graphQL('
            mutation($input: MigrateAgentToProviderInput!) {
                agentRuntimeMigrateAgentToProvider(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'source_deployment_id' => '999999999',
                'destination_machine_id' => '999999999',
                'target_provider' => 'HERMES',
            ],
        ]);

        // company-scoped lookup tacks on " for Company ID <id>" — match the full string.
        $response->assertGraphQLErrorMessage(
            'No Kanvas\Intelligence\Agents\Models\AgentDeployment record found with ID 999999999 for Company ID '
            . auth()->user()->getCurrentCompany()->getId()
        );
    }

    public function testMigrateFromOpenClawJobFailedHookDispatchesEvent(): void
    {
        Event::fake([AgentDeploymentStatusChanged::class]);

        $machine = $this->createTestMachine();
        $sourceDeployment = $this->createTestDeployment($machine, 'provisioning');

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $job = new MigrateFromOpenClawJob(
            $sourceDeployment,
            $machine,
            $app,
            $company,
        );

        $job->failed(new RuntimeException('SSH connection timed out'));

        Event::assertDispatched(AgentDeploymentStatusChanged::class, function (AgentDeploymentStatusChanged $event) use ($sourceDeployment) {
            return $event->deployment->getId() === $sourceDeployment->getId();
        });
    }
}
