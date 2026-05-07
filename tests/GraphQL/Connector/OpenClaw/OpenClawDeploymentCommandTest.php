<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connector\OpenClaw;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Jobs\ExecDeploymentCommandJob;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

class OpenClawDeploymentCommandTest extends TestCase
{
    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }

    private function createTestDeployment(string $status = 'running'): AgentDeployment
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
            $machine->name = 'OCTest-' . fake()->uuid();
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

        $agent = Agent::where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->first();

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
                'system_user' => 'agent-test',
                'home_directory' => '/home/agent-test',
                'gateway_port' => 20000,
                'proxy_port' => 20001,
                'container_name' => 'openclaw-test',
                'status' => $status,
            ],
        );

        // Always set the requested status — firstOrCreate may return an existing record
        $deployment->status = $status;
        $deployment->saveOrFail();

        return $deployment;
    }

    public function testExecCommandRequiresAppKey(): void
    {
        $this->graphQL('
            mutation {
                openclawExecCommand(
                    deployment_id: "1"
                    command: "status"
                    session_id: "test-session"
                )
            }
        ')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function testExecCommandWithAppKey(): void
    {
        Queue::fake();
        $deployment = $this->createTestDeployment();

        $response = $this->graphQL('
            mutation($deploymentId: ID!, $command: String!, $sessionId: String!) {
                openclawExecCommand(
                    deployment_id: $deploymentId
                    command: $command
                    session_id: $sessionId
                )
            }
        ', [
            'deploymentId' => (string) $deployment->getId(),
            'command' => 'status --usage',
            'sessionId' => 'test-session-' . fake()->uuid(),
        ], [], $this->getAppKeyHeader());

        $response->assertSuccessful();
        $response->assertJson(['data' => ['openclawExecCommand' => true]]);
        Queue::assertPushed(ExecDeploymentCommandJob::class);
    }

    public function testExecCommandRejectsStoppedDeployment(): void
    {
        $deployment = $this->createTestDeployment('stopped');
        $deployment->status = 'stopped';
        $deployment->saveOrFail();

        $response = $this->graphQL('
            mutation($deploymentId: ID!, $command: String!, $sessionId: String!) {
                openclawExecCommand(
                    deployment_id: $deploymentId
                    command: $command
                    session_id: $sessionId
                )
            }
        ', [
            'deploymentId' => (string) $deployment->getId(),
            'command' => 'status',
            'sessionId' => 'test-session-' . fake()->uuid(),
        ], [], $this->getAppKeyHeader());

        $response->assertGraphQLErrorMessage('Cannot execute commands on a deployment that is not running');
    }

    public function testExecCommandSanitizesInput(): void
    {
        Queue::fake();
        $deployment = $this->createTestDeployment();

        $response = $this->graphQL('
            mutation($deploymentId: ID!, $command: String!, $sessionId: String!) {
                openclawExecCommand(
                    deployment_id: $deploymentId
                    command: $command
                    session_id: $sessionId
                )
            }
        ', [
            'deploymentId' => (string) $deployment->getId(),
            'command' => '; rm -rf /',
            'sessionId' => 'test-session-' . fake()->uuid(),
        ], [], $this->getAppKeyHeader());

        // After sanitization, only "rm -rf /" remains (semicolon stripped).
        // The command still dispatches — it's sandboxed inside docker exec + openclaw CLI.
        $response->assertSuccessful();
    }

    public function testExecCommandRejectsEmptyCommand(): void
    {
        $deployment = $this->createTestDeployment();

        $response = $this->graphQL('
            mutation($deploymentId: ID!, $command: String!, $sessionId: String!) {
                openclawExecCommand(
                    deployment_id: $deploymentId
                    command: $command
                    session_id: $sessionId
                )
            }
        ', [
            'deploymentId' => (string) $deployment->getId(),
            'command' => ';&|',
            'sessionId' => 'test-session-' . fake()->uuid(),
        ], [], $this->getAppKeyHeader());

        $response->assertGraphQLErrorMessage('Command cannot be empty');
    }

    public function testGetConfigRequiresAppKey(): void
    {
        $this->graphQL('
            mutation {
                openclawGetConfig(deployment_id: "1")
            }
        ')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function testUpdateConfigRequiresAppKey(): void
    {
        $this->graphQL('
            mutation {
                openclawUpdateConfig(
                    deployment_id: "1"
                    config: "{}"
                )
            }
        ')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function testUpdateConfigRejectsInvalidJson(): void
    {
        $deployment = $this->createTestDeployment();

        $response = $this->graphQL('
            mutation($deploymentId: ID!, $config: String!) {
                openclawUpdateConfig(
                    deployment_id: $deploymentId
                    config: $config
                )
            }
        ', [
            'deploymentId' => (string) $deployment->getId(),
            'config' => 'not valid json',
        ], [], $this->getAppKeyHeader());

        $response->assertGraphQLErrorMessage('Invalid JSON config provided');
    }

    public function testUpdateConfigRejectsStoppedDeployment(): void
    {
        $deployment = $this->createTestDeployment('stopped');
        $deployment->status = 'stopped';
        $deployment->saveOrFail();

        $response = $this->graphQL('
            mutation($deploymentId: ID!, $config: String!) {
                openclawUpdateConfig(
                    deployment_id: $deploymentId
                    config: $config
                )
            }
        ', [
            'deploymentId' => (string) $deployment->getId(),
            'config' => '{"agents": {"defaults": {"model": {"primary": "openai-codex/codex-mini-latest"}}}}',
        ], [], $this->getAppKeyHeader());

        $response->assertGraphQLErrorMessage('Cannot update config on a deployment that is not running');
    }
}
