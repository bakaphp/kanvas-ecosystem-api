<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentOnMachineAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerLogsAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerStatusAction;
use Kanvas\Connectors\OpenClaw\Actions\LaunchAgentOnMachineAction;
use Kanvas\Connectors\OpenClaw\Actions\RestartAgentContainerAction;
use Kanvas\Connectors\OpenClaw\Actions\TerminateAgentOnMachineAction;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Connectors\Traits\HasOpenClawConfiguration;
use Tests\TestCase;
use Throwable;

class AgentDeploymentTest extends TestCase
{
    use HasOpenClawConfiguration;

    protected function createTestAgent(array $overrides = []): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(array_merge([
                'soul' => 'You are a helpful test assistant.',
                'instructions' => 'Step 1: Greet. Step 2: Help.',
                'output_format' => 'Respond in plain text.',
                'identity' => ['name' => 'TestBot', 'emoji' => '🤖', 'vibe' => 'friendly'],
                'deployment_status' => 'pending',
            ], $overrides));
    }

    public function testAgentMachinePortAllocation(): void
    {
        $machine = $this->createTestMachine();

        $ports = $machine->allocatePortPair();

        $this->assertArrayHasKey('gateway_port', $ports);
        $this->assertArrayHasKey('proxy_port', $ports);
        $this->assertEquals($machine->port_range_start, $ports['gateway_port']);
        $this->assertEquals($machine->port_range_start + 1, $ports['proxy_port']);
    }

    public function testAgentMachineCapacityCheck(): void
    {
        $machine = $this->createTestMachine();

        $this->assertTrue($machine->hasCapacity());
    }

    public function testDeploymentQueryGraphQL(): void
    {
        $this->graphQL('
            query {
                agentDeployments {
                    data {
                        id
                        status
                        container_name
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'agentDeployments' => [
                    'data',
                ],
            ],
        ]);
    }

    public function testLaunchAgentGraphQL(): void
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $agent = $this->createTestAgent();
        $machine = $this->createTestMachine();

        $response = $this->graphQL('
            mutation($input: LaunchAgentInput!) {
                launchAgent(input: $input) {
                    id
                    status
                    system_user
                    container_name
                }
            }
        ', [
            'input' => [
                'agent_id' => $agent->getId(),
                'machine_id' => $machine->getId(),
            ],
        ])
        ->assertSuccessful();

        $data = $response->json('data.launchAgent');
        $this->assertEquals('provisioning', $data['status']);
        $this->assertStringContainsString('agent-', $data['system_user']);
    }

    public function testTerminateAgentGraphQL(): void
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->createTestAgent();
        $machine = $this->createTestMachine();

        $deployment = new LaunchAgentOnMachineAction(
            $agent,
            $machine,
            $app,
            $company,
        )->execute();

        $this->graphQL('
            mutation($deployment_id: ID!) {
                terminateAgent(deployment_id: $deployment_id)
            }
        ', ['deployment_id' => $deployment->getId()])
        ->assertSuccessful()
        ->assertJson(['data' => ['terminateAgent' => true]]);
    }

    public function testChatWithAgentOnMachineRequiresDeployment(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agent does not have an active deployment');

        $agent = $this->createTestAgent();

        new ChatWithAgentOnMachineAction($agent, 'Hello')->execute();
    }

    public function testFullDeploymentLifecycle(): void
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->createTestAgent();
        $machine = $this->createTestMachine();

        $deployment = new LaunchAgentOnMachineAction(
            $agent,
            $machine,
            $app,
            $company,
        )->execute();

        $this->assertEquals(DeploymentStatusEnum::RUNNING->value, $deployment->status);
        $this->assertNotNull($deployment->launched_at);

        $statusResult = new GetAgentContainerStatusAction($deployment)->execute();
        $this->assertInstanceOf(AgentDeployment::class, $statusResult);

        $logs = new GetAgentContainerLogsAction($deployment, 10)->execute();
        $this->assertIsString($logs);

        $restarted = new RestartAgentContainerAction($deployment)->execute();
        $this->assertEquals(DeploymentStatusEnum::RUNNING->value, $restarted->status);

        $chatResponse = new ChatWithAgentOnMachineAction($agent, 'Hello')->execute();
        $this->assertNotEmpty($chatResponse);

        new TerminateAgentOnMachineAction($deployment)->execute();
        $deployment->refresh();
        $this->assertEquals(DeploymentStatusEnum::TERMINATED->value, $deployment->status);
        $this->assertNotNull($deployment->terminated_at);
    }

    public function testDeployAndKeepRunning(): void
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->setupProviderKeys($app);

        $agent = $this->createTestAgent(['name' => 'test-persistent-agent']);
        $machine = $this->createTestMachine();

        $this->cleanupPreviousDeployment($machine, 'agent-' . $agent->slug);

        $deployment = new LaunchAgentOnMachineAction(
            $agent,
            $machine,
            $app,
            $company,
        )->execute();

        $this->assertEquals(DeploymentStatusEnum::RUNNING->value, $deployment->status);

        echo "\n\n=== AGENT DEPLOYED AND RUNNING ===\n";
        echo "Machine: {$machine->host}\n";
        echo "Linux user: {$deployment->system_user}\n";
        echo "Home dir: {$deployment->home_directory}\n";
        echo "Container: {$deployment->container_name}\n";
        echo "Gateway port: {$deployment->gateway_port}\n";
        echo "Proxy port: {$deployment->proxy_port}\n";
        echo "Agent slug: {$agent->slug}\n";
        echo "\nTo chat via SSH:\n";
        echo "  ssh {$machine->ssh_user}@{$machine->host} \"sudo -u {$deployment->system_user} docker exec {$deployment->container_name} node /app/dist/index.js agent --agent {$agent->slug} --message 'Hello'\"\n";
        echo "\nTo clean up later:\n";
        echo "  ssh {$machine->ssh_user}@{$machine->host} \"sudo -u {$deployment->system_user} bash -c 'cd {$deployment->home_directory}/.openclaw && docker compose down' && sudo userdel -r {$deployment->system_user}\"\n";
        echo "===================================\n\n";
    }

    public function testDeployChatAndCleanup(): void
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->setupProviderKeys($app);

        $agent = $this->createTestAgent(['name' => 'test-chat-agent']);
        $machine = $this->createTestMachine();

        $this->cleanupPreviousDeployment($machine, 'agent-' . $agent->slug);

        $deployment = new LaunchAgentOnMachineAction(
            $agent,
            $machine,
            $app,
            $company,
        )->execute();

        $this->assertEquals(DeploymentStatusEnum::RUNNING->value, $deployment->status);

        // Wait for gateway to be ready
        sleep(5);

        // Chat with the agent via docker exec
        $client = SshClient::fromMachine($machine);
        $response = $client->exec(
            'docker exec ' . escapeshellarg($deployment->container_name)
            . ' node /app/dist/index.js agent'
            . ' --agent ' . escapeshellarg($agent->slug)
            . ' --message ' . escapeshellarg('Hello, what is your name?')
            . ' 2>&1',
            120,
        );
        $client->disconnect();

        echo "\n=== AGENT RESPONSE ===\n{$response}\n======================\n";

        $this->assertNotEmpty(trim($response), 'Agent should return a non-empty response');

        // Cleanup
        $this->cleanupPreviousDeployment($machine, $deployment->system_user);
    }

    private function setupProviderKeys(AppInterface $app): void
    {
        $googleKey = env('TEST_OPENCLAW_GOOGLE_API_KEY', '');
        if (! empty($googleKey)) {
            $app->set(ConfigurationEnum::GOOGLE_API_KEY->value, $googleKey);
        }

        $anthropicKey = env('TEST_OPENCLAW_ANTHROPIC_API_KEY', '');
        if (! empty($anthropicKey)) {
            $app->set(ConfigurationEnum::ANTHROPIC_API_KEY->value, $anthropicKey);
        }

        $geminiKey = env('TEST_OPENCLAW_GEMINI_API_KEY', '');
        if (! empty($geminiKey)) {
            $app->set(ConfigurationEnum::GEMINI_API_KEY->value, $geminiKey);
        }
    }

    private function cleanupPreviousDeployment(AgentMachine $machine, string $systemUser): void
    {
        $homeDir = '/home/' . $systemUser;
        $openclawDir = $homeDir . '/.openclaw';

        try {
            $client = SshClient::fromMachine($machine);
            $client->exec(
                'id ' . escapeshellarg($systemUser) . ' &>/dev/null && '
                . 'sudo -u ' . escapeshellarg($systemUser)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose down 2>/dev/null')
                . '; sudo userdel -r ' . escapeshellarg($systemUser) . ' 2>/dev/null; true'
            );
            $client->disconnect();
        } catch (Throwable) {
            // Ignore cleanup failures
        }
    }
}
