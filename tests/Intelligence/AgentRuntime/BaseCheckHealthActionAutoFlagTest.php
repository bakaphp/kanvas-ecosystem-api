<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCheckHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

class BaseCheckHealthActionAutoFlagTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testConsecutiveFailuresFlagDeploymentFailedAndStopReprobing(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $deployment = $this->makeRunningDeployment($app, $company->getId(), $agent->getId());

        for ($i = 1; $i < BaseCheckHealthAction::MAX_CONSECUTIVE_FAILURES; $i++) {
            $this->probeWith($deployment, HealthCheckResultEnum::FAILED);
            $deployment->refresh();

            $this->assertSame(
                DeploymentStatusEnum::RUNNING->value,
                $deployment->status,
                "Should still be running before the failure threshold (strike {$i}).",
            );
            $this->assertSame($i, $deployment->health_check_failures);
        }

        $this->probeWith($deployment, HealthCheckResultEnum::FAILED);
        $deployment->refresh();

        $this->assertSame(DeploymentStatusEnum::FAILED->value, $deployment->status);
        $this->assertSame(BaseCheckHealthAction::MAX_CONSECUTIVE_FAILURES, $deployment->health_check_failures);
        $this->assertNotNull($deployment->error_message);
    }

    public function testOkResetsTheFailureCounter(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $deployment = $this->makeRunningDeployment($app, $company->getId(), $agent->getId());

        $this->probeWith($deployment, HealthCheckResultEnum::FAILED);
        $this->probeWith($deployment, HealthCheckResultEnum::FAILED);
        $deployment->refresh();
        $this->assertSame(2, $deployment->health_check_failures);

        $this->probeWith($deployment, HealthCheckResultEnum::OK);
        $deployment->refresh();

        $this->assertSame(0, $deployment->health_check_failures);
        $this->assertSame(DeploymentStatusEnum::RUNNING->value, $deployment->status);
    }

    private function probeWith(AgentDeployment $deployment, HealthCheckResultEnum $result): void
    {
        new class ($deployment, $result) extends BaseCheckHealthAction {
            public function __construct(
                AgentDeployment $deployment,
                private readonly HealthCheckResultEnum $result,
            ) {
                parent::__construct($deployment);
            }

            protected function probe(Agent $agent): HealthCheckResultEnum
            {
                return $this->result;
            }
        }->execute();
    }

    private function makeRunningDeployment(Apps $app, int $companyId, int $agentId): AgentDeployment
    {
        $machine = new AgentMachine();
        $machine->apps_id = $app->getId();
        $machine->companies_id = $companyId;
        $machine->name = 'AutoFlag Test Machine ' . uniqid();
        $machine->host = '10.10.10.12';
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
        $deployment->system_user = 'agent-autoflag-user';
        $deployment->home_directory = '/home/agent-autoflag-user';
        $deployment->gateway_port = 20000;
        $deployment->proxy_port = 20001;
        $deployment->container_name = 'agent-autoflag-container';
        $deployment->provider = 'openclaw';
        $deployment->status = DeploymentStatusEnum::RUNNING->value;
        $deployment->saveOrFail();

        return $deployment;
    }
}
