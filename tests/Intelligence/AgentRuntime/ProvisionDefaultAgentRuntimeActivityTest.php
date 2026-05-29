<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Hermes\Jobs\LaunchAgentJob as HermesLaunchAgentJob;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Connectors\OpenClaw\Jobs\LaunchAgentJob as OpenClawLaunchAgentJob;
use Kanvas\Intelligence\AgentRuntime\Activities\ProvisionDefaultAgentRuntimeActivity;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentRuntimeSettingEnum;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

class ProvisionDefaultAgentRuntimeActivityTest extends TestCase
{
    use HasIntegrationCompany;

    public function testCopiesDefaultMachineAndDeploysAllHermesAgentsRegardlessOfChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $sourceCompany = $user->getCurrentCompany();
        $targetCompany = Companies::factory()->create();
        $this->setIntegration($app, IntegrationsEnum::INTERNAL, InternalHandler::class, $targetCompany, $user);

        $sourceMachine = $this->createMachine($sourceCompany, 'runtime-source-' . fake()->uuid());
        $app->set(AgentRuntimeSettingEnum::DEFAULT_MACHINE_ID->value, $sourceMachine->getId());

        $channelAgent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $targetCompany->getId(),
            'user_id' => $user->getId(),
            'name' => 'Channel Runtime Agent ' . fake()->uuid(),
            'config' => ['existing' => true],
        ]);
        $channelAgent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test');
        $channelAgent->set(AgentChannelTokenEnum::SLACK_APP_TOKEN->value, 'xapp-test');

        $channelLessAgent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $targetCompany->getId(),
            'user_id' => $user->getId(),
            'name' => 'Channel-less Runtime Agent ' . fake()->uuid(),
        ]);

        Queue::fake();
        Notification::fake();

        $result = $this->activity()->execute($user, $app, [
            'company' => $targetCompany,
            'provider' => true,
            'welcome_changed' => true,
            'welcome_previous' => 0,
            'welcome_current' => 1,
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(AgentProviderEnum::HERMES->value, $result['provider']);
        $this->assertContains($channelAgent->getId(), $result['agent_ids']);
        $this->assertContains($channelLessAgent->getId(), $result['agent_ids']);

        // Hermes is reachable via its always-on HTTP API server, so a channel-less agent
        // deploys just like a channel-equipped one — both land in deployed_agent_ids.
        $this->assertContains($channelAgent->getId(), $result['deployed_agent_ids']);
        $this->assertContains($channelLessAgent->getId(), $result['deployed_agent_ids']);

        $newMachine = AgentMachine::where('id', $result['machine_id'])->firstOrFail();
        $this->assertSame($targetCompany->getId(), (int) $newMachine->companies_id);
        $this->assertSame($sourceMachine->host, $newMachine->host);

        $channelAgent->refresh();
        $channelLessAgent->refresh();

        $this->assertTrue($channelAgent->config['runtime']);
        $this->assertTrue($channelAgent->config['existing']);
        $this->assertSame('Web Chat', $channelAgent->config['channel']);
        $this->assertSame('Gemini', $channelAgent->config['language_model']);

        $this->assertTrue($channelLessAgent->config['runtime']);
        $this->assertSame('Web Chat', $channelLessAgent->config['channel']);
        $this->assertSame('Gemini', $channelLessAgent->config['language_model']);

        foreach ([$channelAgent, $channelLessAgent] as $agent) {
            $deployment = AgentDeployment::where('agent_id', $agent->getId())
                ->where('agent_machine_id', $newMachine->getId())
                ->firstOrFail();

            $this->assertSame(AgentProviderEnum::HERMES->value, $deployment->provider);
            $this->assertSame('provisioning', $deployment->status);
        }

        Queue::assertPushed(HermesLaunchAgentJob::class, 2);
        Notification::assertNothingSent();
    }

    public function testProvisionWithOpenClawProviderOnlyDeploysChannelReadyAgents(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $sourceCompany = $user->getCurrentCompany();
        $targetCompany = Companies::factory()->create();
        $this->setIntegration($app, IntegrationsEnum::INTERNAL, InternalHandler::class, $targetCompany, $user);

        $sourceMachine = $this->createMachine($sourceCompany, 'runtime-source-' . fake()->uuid());
        $app->set(AgentRuntimeSettingEnum::DEFAULT_MACHINE_ID->value, $sourceMachine->getId());

        $channelAgent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $targetCompany->getId(),
            'user_id' => $user->getId(),
            'name' => 'OpenClaw Channel Agent ' . fake()->uuid(),
        ]);
        $channelAgent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test');
        $channelAgent->set(AgentChannelTokenEnum::SLACK_APP_TOKEN->value, 'xapp-test');

        $channelLessAgent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $targetCompany->getId(),
            'user_id' => $user->getId(),
            'name' => 'OpenClaw Channel-less Agent ' . fake()->uuid(),
        ]);

        Queue::fake();
        Notification::fake();

        $result = $this->activity()->execute($user, $app, [
            'company' => $targetCompany,
            'provider' => AgentProviderEnum::OPENCLAW->value,
            'welcome_changed' => true,
            'welcome_previous' => 0,
            'welcome_current' => 1,
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(AgentProviderEnum::OPENCLAW->value, $result['provider']);

        // OpenClaw still requires a messaging channel: the channel agent deploys, the
        // channel-less one is configured but skipped.
        $this->assertContains($channelAgent->getId(), $result['deployed_agent_ids']);
        $this->assertNotContains($channelLessAgent->getId(), $result['deployed_agent_ids']);

        $this->assertSame(1, AgentDeployment::where('agent_id', $channelAgent->getId())->count());
        $this->assertSame(0, AgentDeployment::where('agent_id', $channelLessAgent->getId())->count());

        Queue::assertPushed(OpenClawLaunchAgentJob::class, 1);
        Notification::assertNothingSent();
    }

    public function testNoConfigIsNoOp(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $app->set(AgentRuntimeSettingEnum::DEFAULT_MACHINE_ID->value, '');
        $company = Companies::factory()->create();
        $this->setIntegration($app, IntegrationsEnum::INTERNAL, InternalHandler::class, $company, $user);

        Queue::fake();
        Notification::fake();

        $result = $this->activity()->execute($user, $app, [
            'company' => $company,
            'welcome_changed' => true,
            'welcome_previous' => 0,
            'welcome_current' => 1,
        ]);

        $this->assertSame('no default machine configured', $result['msg']);
        Queue::assertNotPushed(HermesLaunchAgentJob::class);
        Notification::assertNothingSent();
    }

    public function testWelcomeTransitionGateIsRequired(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $sourceCompany = $user->getCurrentCompany();
        $company = Companies::factory()->create();
        $sourceMachine = $this->createMachine($sourceCompany, 'runtime-source-' . fake()->uuid());
        $app->set(AgentRuntimeSettingEnum::DEFAULT_MACHINE_ID->value, $sourceMachine->getId());
        $this->setIntegration($app, IntegrationsEnum::INTERNAL, InternalHandler::class, $company, $user);

        Queue::fake();
        Notification::fake();

        $result = $this->activity()->execute($user, $app, [
            'company' => $company,
            'welcome_changed' => false,
            'welcome_previous' => 1,
            'welcome_current' => 1,
        ]);

        $this->assertSame('welcome was not completed', $result['msg']);
        Queue::assertNotPushed(HermesLaunchAgentJob::class);
        Notification::assertNothingSent();
    }

    public function testPortAllocationIsHostAware(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $host = 'host-aware-' . fake()->uuid();
        $firstMachine = $this->createMachine($company, $host, 26000, 26010, 1);
        $secondMachine = $this->createMachine($company, $host, 26000, 26010, 1);
        $agent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
        ]);

        AgentDeployment::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'agent_machine_id' => $firstMachine->getId(),
            'system_user' => 'agent-host-aware',
            'home_directory' => '/home/agent-host-aware',
            'gateway_port' => 26000,
            'proxy_port' => 26001,
            'container_name' => 'hermes-agent-host-aware',
            'provider' => true,
            'status' => 'running',
        ]);

        $this->assertFalse($secondMachine->hasCapacity());
        $secondMachine->max_agents = 2;
        $secondMachine->saveOrFail();

        $this->assertSame([
            'gateway_port' => 26002,
            'proxy_port' => 26003,
        ], $secondMachine->allocatePortPair());
    }

    private function createMachine(
        Companies $company,
        string $host,
        int $portStart = 25000,
        int $portEnd = 25100,
        int $maxAgents = 100,
    ): AgentMachine {
        $app = app(Apps::class);

        $machine = new AgentMachine();
        $machine->apps_id = $app->getId();
        $machine->companies_id = $company->getId();
        $machine->name = 'Provision Runtime Machine ' . fake()->uuid();
        $machine->host = $host;
        $machine->ssh_port = 22;
        $machine->ssh_user = 'test';
        $machine->ssh_private_key = 'invalid-key';
        $machine->region = 'test';
        $machine->port_range_start = $portStart;
        $machine->port_range_end = $portEnd;
        $machine->max_agents = $maxAgents;
        $machine->is_active = true;
        $machine->saveOrFail();

        return $machine;
    }

    private function activity(): ProvisionDefaultAgentRuntimeActivity
    {
        return new ProvisionDefaultAgentRuntimeActivity(
            index: 0,
            now: now()->toDateTimeString(),
            storedWorkflow: new StoredWorkflow(),
            arguments: [],
        );
    }
}
