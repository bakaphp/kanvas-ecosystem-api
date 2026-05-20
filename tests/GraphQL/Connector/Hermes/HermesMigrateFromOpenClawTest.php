<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connector\Hermes;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Actions\MigrateFromOpenClawAction;
use Kanvas\Connectors\Hermes\Jobs\MigrateFromOpenClawJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentDeploymentMissingChannelIntegrationNotification;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
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

    public function testMigrateFromOpenClawActionRequiresSlackOrTelegramIntegration(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Dedicated fresh agent with no Slack/Telegram channel tokens, so the readiness
        // check blocks the migration before any SSH connection is attempted. Reusing an
        // existing agent is non-deterministic — a sibling test may have left Telegram or
        // Slack tokens on it (channel tokens are custom fields that outlive the test
        // transaction), which would skip the check and fail on the fake SSH key instead.
        $agent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
        ]);

        $machine = $this->createTestMachine();
        $sourceDeployment = $this->createTestDeployment($machine, agent: $agent);

        try {
            new MigrateFromOpenClawAction($sourceDeployment, $machine, $app, $company)->execute();
            $this->fail('Expected migration deployment to be blocked without Slack or Telegram credentials.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Slack integration or Telegram integration', $e->getMessage());
        }

        $this->assertSame(
            0,
            AgentDeployment::where('agent_id', $agent->getId())
                ->where('provider', AgentProviderEnum::HERMES->value)
                ->count(),
        );
        Notification::assertSentTo($agent->user, AgentDeploymentMissingChannelIntegrationNotification::class);
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
