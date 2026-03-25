<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connector\OpenClaw;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\SyncAgentWorkspaceAction;
use Kanvas\Connectors\OpenClaw\Actions\UpdateAgentSwarmHierarchyAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use phpseclib3\Exception\NoKeyLoadedException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;
use Tests\TestCase;

class OpenClawAgentActionsTest extends TestCase
{
    private function createAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
            'soul' => 'You are a helpful assistant.',
            'instructions' => 'Answer questions clearly.',
        ]);
    }

    private function createSwarmWithMembers(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $manager = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
            'name' => 'Lars',
        ]);

        $worker = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
            'name' => 'Scout',
        ]);

        $swarm = new AgentSwarm();
        $swarm->apps_id = $app->getId();
        $swarm->companies_id = $company->getId();
        $swarm->users_id = $user->getId();
        $swarm->name = 'Sales Team ' . fake()->uuid();
        $swarm->description = 'Handle inbound sales leads';
        $swarm->status = 'active';
        $swarm->is_active = true;
        $swarm->saveOrFail();

        $managerMember = AgentSwarmMember::create([
            'agent_swarm_id' => $swarm->getId(),
            'agent_id' => $manager->getId(),
            'role' => 'Team Lead',
        ]);

        $workerMember = AgentSwarmMember::create([
            'agent_swarm_id' => $swarm->getId(),
            'agent_id' => $worker->getId(),
            'role' => 'Lead Qualifier',
            'parent_id' => $managerMember->getId(),
        ]);

        return [
            'swarm' => $swarm,
            'manager' => $manager,
            'worker' => $worker,
            'managerMember' => $managerMember,
            'workerMember' => $workerMember,
        ];
    }

    public function testUpdateAgentSwarmHierarchyUpdatesUserContext(): void
    {
        $data = $this->createSwarmWithMembers();
        $worker = $data['worker'];

        $result = new UpdateAgentSwarmHierarchyAction($worker)->execute();

        $this->assertTrue($result['success']);

        $worker->refresh();
        $this->assertStringContainsString('## Team Structure', $worker->user_context);
        $this->assertStringContainsString('Lead Qualifier', $worker->user_context);
        $this->assertStringContainsString('Lars', $worker->user_context);
        $this->assertStringContainsString('You report to', $worker->user_context);
    }

    public function testUpdateAgentSwarmHierarchyIncludesDirectReports(): void
    {
        $data = $this->createSwarmWithMembers();
        $manager = $data['manager'];

        $result = new UpdateAgentSwarmHierarchyAction($manager)->execute();

        $this->assertTrue($result['success']);

        $manager->refresh();
        $this->assertStringContainsString('## Team Structure', $manager->user_context);
        $this->assertStringContainsString('Team Lead', $manager->user_context);
        $this->assertStringContainsString('Scout', $manager->user_context);
        $this->assertStringContainsString('Your direct reports', $manager->user_context);
    }

    public function testUpdateAgentSwarmHierarchyIncludesSwarmDescription(): void
    {
        $data = $this->createSwarmWithMembers();
        $worker = $data['worker'];

        new UpdateAgentSwarmHierarchyAction($worker)->execute();

        $worker->refresh();
        $this->assertStringContainsString('Handle inbound sales leads', $worker->user_context);
    }

    public function testUpdateAgentSwarmHierarchyPreservesExistingContext(): void
    {
        $data = $this->createSwarmWithMembers();
        $worker = $data['worker'];
        $worker->user_context = 'Some existing context about the user.';
        $worker->saveOrFail();

        new UpdateAgentSwarmHierarchyAction($worker)->execute();

        $worker->refresh();
        $this->assertStringContainsString('Some existing context about the user.', $worker->user_context);
        $this->assertStringContainsString('## Team Structure', $worker->user_context);
    }

    public function testUpdateAgentSwarmHierarchyReplacesExistingTeamSection(): void
    {
        $data = $this->createSwarmWithMembers();
        $worker = $data['worker'];
        $worker->user_context = "Previous info.\n\n## Team Structure\n\nOld team data here.";
        $worker->saveOrFail();

        new UpdateAgentSwarmHierarchyAction($worker)->execute();

        $worker->refresh();
        $this->assertStringContainsString('Previous info.', $worker->user_context);
        $this->assertStringContainsString('Lead Qualifier', $worker->user_context);
        $this->assertStringNotContainsString('Old team data here', $worker->user_context);
    }

    public function testUpdateAgentSwarmHierarchyNoSwarmClearsSection(): void
    {
        $agent = $this->createAgent();
        $agent->user_context = "Some context.\n\n## Team Structure\n\nOld data.";
        $agent->saveOrFail();

        new UpdateAgentSwarmHierarchyAction($agent)->execute();

        $agent->refresh();
        $this->assertStringContainsString('Some context.', $agent->user_context);
        $this->assertStringNotContainsString('## Team Structure', $agent->user_context);
    }

    public function testSyncAgentWorkspaceFailsWithoutSsh(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = $this->createAgent();

        $machine = AgentMachine::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->first();

        if (! $machine) {
            $machine = new AgentMachine();
            $machine->apps_id = $app->getId();
            $machine->companies_id = $company->getId();
            $machine->name = 'SyncTest-' . fake()->uuid();
            $machine->host = '127.0.0.1';
            $machine->ssh_port = 22;
            $machine->ssh_user = 'test';
            $machine->ssh_private_key = 'invalid-key';
            $machine->port_range_start = 20000;
            $machine->port_range_end = 30000;
            $machine->max_agents = 100;
            $machine->is_active = true;
            $machine->saveOrFail();
        }

        $deployment = AgentDeployment::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'agent_id' => $agent->getId(),
                'agent_machine_id' => $machine->getId(),
            ],
            [
                'companies_id' => $company->getId(),
                'system_user' => 'agent-sync-test',
                'home_directory' => '/home/agent-sync-test',
                'gateway_port' => 29000,
                'proxy_port' => 29001,
                'container_name' => 'openclaw-sync-test',
                'status' => 'running',
            ],
        );

        $this->expectException(NoKeyLoadedException::class);
        new SyncAgentWorkspaceAction($agent, $deployment)->execute();
    }
}
