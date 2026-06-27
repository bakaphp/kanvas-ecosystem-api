<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

class CheckAgentRuntimeHealthCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testOrphanedRunningDeploymentIsTerminatedNotReported(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // Soft-delete the agent so the health probe's getByIdFromCompanyApp lookup fails —
        // this is the orphaned-deployment state behind the recurring Sentry noise.
        $agent->is_deleted = 1;
        $agent->saveOrFail();

        $deployment = $this->makeRunningDeployment($app, $company->getId(), $agent->getId());

        $this->artisan('kanvas:agent-runtime-check-health', ['--app' => $app->getId()])
            ->assertSuccessful();

        $deployment->refresh();
        $this->assertSame(
            DeploymentStatusEnum::TERMINATED->value,
            $deployment->status,
            'Orphaned deployment should be terminated so it stops being re-probed.',
        );
        $this->assertNotNull($deployment->terminated_at);
    }

    private function makeRunningDeployment(Apps $app, int $companyId, int $agentId): AgentDeployment
    {
        $machine = new AgentMachine();
        $machine->apps_id = $app->getId();
        $machine->companies_id = $companyId;
        $machine->name = 'Health Test Machine ' . uniqid();
        $machine->host = '10.10.10.11';
        $machine->ssh_port = 22;
        $machine->ssh_user = 'deploy';
        $machine->ssh_private_key = 'test-key';
        $machine->region = 'us-east-1';
        $machine->port_range_start = 20000;
        $machine->port_range_end = 30000;
        $machine->max_agents = 100;
        $machine->is_active = true;
        $machine->is_connected = false;
        $machine->saveOrFail();

        $deployment = new AgentDeployment();
        $deployment->apps_id = $app->getId();
        $deployment->companies_id = $companyId;
        $deployment->agent_id = $agentId;
        $deployment->agent_machine_id = $machine->getId();
        $deployment->system_user = 'agent-health-test-user';
        $deployment->home_directory = '/home/agent-health-test-user';
        $deployment->gateway_port = 20000;
        $deployment->proxy_port = 20001;
        $deployment->container_name = 'agent-health-container';
        $deployment->provider = 'openclaw';
        $deployment->status = DeploymentStatusEnum::RUNNING->value;
        $deployment->saveOrFail();

        return $deployment;
    }
}
