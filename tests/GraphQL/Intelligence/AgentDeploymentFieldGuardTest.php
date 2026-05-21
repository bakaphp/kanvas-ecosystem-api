<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Models as BouncerModels;
use Tests\TestCase;

/**
 * Covers the @adminOnlyField directive applied to AgentDeploymentType /
 * AgentMachineType. Both types are reachable by any authenticated user
 * through the @guard-level agentUsageSnapshots query, so non-admins can
 * see the objects but must not see their infrastructure-sensitive values.
 */
class AgentDeploymentFieldGuardTest extends TestCase
{
    private const SENSITIVE_QUERY = '
        query {
            agentUsageSnapshots {
                data {
                    id
                    deployment {
                        id
                        status
                        provider
                        system_user
                        home_directory
                        container_name
                        gateway_port
                        proxy_port
                        machine {
                            id
                            name
                            region
                            host
                            ssh_user
                            ssh_port
                            port_range_start
                            port_range_end
                        }
                    }
                }
            }
        }
    ';

    public function testAdminSeesSensitiveDeploymentAndMachineFields(): void
    {
        $app = app(Apps::class);
        /** @var Users $admin */
        $admin = auth()->user();
        $this->assertTrue($admin->isAdmin(), 'default test user is expected to be an admin');

        $snapshot = $this->createSnapshotStack($app, $admin->getCurrentCompany());

        $response = $this->graphQL(self::SENSITIVE_QUERY)->assertSuccessful();
        $this->assertNull($response->json('errors'), 'redaction must never raise GraphQL errors');

        $deployment = $this->findDeployment(
            $response->json('data.agentUsageSnapshots.data'),
            $snapshot->getId(),
        );

        $this->assertSame('agent-guard-test-user', $deployment['system_user']);
        $this->assertSame('/home/agent-guard-test-user', $deployment['home_directory']);
        $this->assertSame('agent-guard-container', $deployment['container_name']);
        $this->assertSame(20000, $deployment['gateway_port']);
        $this->assertSame(20001, $deployment['proxy_port']);

        $this->assertSame('10.10.10.10', $deployment['machine']['host']);
        $this->assertSame('deploy', $deployment['machine']['ssh_user']);
        $this->assertSame(22, $deployment['machine']['ssh_port']);
        $this->assertSame(20000, $deployment['machine']['port_range_start']);
        $this->assertSame(30000, $deployment['machine']['port_range_end']);
    }

    public function testNonAdminGetsNullForSensitiveFieldsButKeepsPublicOnes(): void
    {
        $app = app(Apps::class);

        $nonAdmin = $this->createNonAdminUser();
        $this->assertFalse($nonAdmin->isAdmin(), 'test setup failed: user must not be an admin');

        $snapshot = $this->createSnapshotStack($app, $nonAdmin->getCurrentCompany());

        $this->actingAs($nonAdmin, 'api');

        $response = $this->graphQL(self::SENSITIVE_QUERY)->assertSuccessful();
        $this->assertNull(
            $response->json('errors'),
            'directive must return null, not throw — a thrown error would null-propagate the object',
        );

        $deployment = $this->findDeployment(
            $response->json('data.agentUsageSnapshots.data'),
            $snapshot->getId(),
        );

        $this->assertSame('running', $deployment['status'], 'public field stays visible');
        $this->assertSame('openclaw', $deployment['provider'], 'public field stays visible');
        $this->assertStringStartsWith('Guard Test Machine', $deployment['machine']['name'], 'public field stays visible');
        $this->assertSame('us-east-1', $deployment['machine']['region'], 'public field stays visible');

        $this->assertNull($deployment['system_user']);
        $this->assertNull($deployment['home_directory']);
        $this->assertNull($deployment['container_name']);
        $this->assertNull($deployment['gateway_port']);
        $this->assertNull($deployment['proxy_port']);
        $this->assertNull($deployment['machine']['host']);
        $this->assertNull($deployment['machine']['ssh_user']);
        $this->assertNull($deployment['machine']['ssh_port']);
        $this->assertNull($deployment['machine']['port_range_start']);
        $this->assertNull($deployment['machine']['port_range_end']);
    }

    public function testNonAdminListsMachinesDirectlyWithRedactedFields(): void
    {
        $app = app(Apps::class);
        $nonAdmin = $this->createNonAdminUser();
        $this->assertFalse($nonAdmin->isAdmin(), 'test setup failed: user must not be an admin');

        $this->createSnapshotStack($app, $nonAdmin->getCurrentCompany());

        $this->actingAs($nonAdmin, 'api');

        $response = $this->graphQL('
            query {
                agentMachines {
                    data {
                        id
                        name
                        region
                        host
                        ssh_user
                        ssh_port
                        port_range_start
                        port_range_end
                    }
                }
            }
        ')->assertSuccessful();
        $this->assertNull($response->json('errors'));

        $machines = $response->json('data.agentMachines.data');
        $this->assertNotEmpty($machines, 'non-admin should see machines in their company');

        foreach ($machines as $machine) {
            $this->assertNotNull($machine['name'], 'public field stays visible');
            $this->assertNull($machine['host']);
            $this->assertNull($machine['ssh_user']);
            $this->assertNull($machine['ssh_port']);
            $this->assertNull($machine['port_range_start']);
            $this->assertNull($machine['port_range_end']);
        }
    }

    public function testNonAdminListsDeploymentsDirectlyWithRedactedFields(): void
    {
        $app = app(Apps::class);
        $nonAdmin = $this->createNonAdminUser();

        $this->createSnapshotStack($app, $nonAdmin->getCurrentCompany());

        $this->actingAs($nonAdmin, 'api');

        $response = $this->graphQL('
            query {
                agentDeployments {
                    data {
                        id
                        status
                        provider
                        system_user
                        home_directory
                        container_name
                        gateway_port
                        proxy_port
                    }
                }
            }
        ')->assertSuccessful();
        $this->assertNull($response->json('errors'));

        $deployments = $response->json('data.agentDeployments.data');
        $this->assertNotEmpty($deployments, 'non-admin should see deployments in their company');

        foreach ($deployments as $deployment) {
            $this->assertSame('running', $deployment['status'], 'public field stays visible');
            $this->assertNull($deployment['system_user']);
            $this->assertNull($deployment['home_directory']);
            $this->assertNull($deployment['container_name']);
            $this->assertNull($deployment['gateway_port']);
            $this->assertNull($deployment['proxy_port']);
        }
    }

    public function testNonAdminCannotListBackups(): void
    {
        $nonAdmin = $this->createNonAdminUser();
        $this->actingAs($nonAdmin, 'api');

        $response = $this->graphQL('
            query {
                agentBackups {
                    data {
                        id
                    }
                }
            }
        ')->assertSuccessful();

        $this->assertNotNull(
            $response->json('errors'),
            'agentBackups must stay admin-only — it has no field redaction',
        );
        $this->assertNull($response->json('data.agentBackups'));
    }

    /**
     * Registered users get an Admin role by default; strip every assigned
     * role (scope-agnostic) so isAdmin() resolves false while the user keeps
     * a real company/app context the company-scoped query needs.
     */
    private function createNonAdminUser(): Users
    {
        $user = $this->createUser();

        BouncerModels::query('assigned_roles')
            ->where('entity_id', $user->getId())
            ->where('entity_type', new Users()->getMorphClass())
            ->delete();
        Bouncer::refresh();

        return $user;
    }

    private function createSnapshotStack(Apps $app, Companies $company): AgentUsageSnapshot
    {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'soul' => 'You are a helpful test assistant.',
                'instructions' => 'Step 1: Greet.',
                'output_format' => 'Respond in plain text.',
                'identity' => ['name' => 'GuardBot', 'emoji' => '🤖', 'vibe' => 'friendly'],
                'deployment_status' => 'pending',
            ]);

        $machine = new AgentMachine();
        $machine->apps_id = $app->getId();
        $machine->companies_id = $company->getId();
        $machine->name = 'Guard Test Machine ' . uniqid();
        $machine->host = '10.10.10.10';
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
        $deployment->companies_id = $company->getId();
        $deployment->agent_id = $agent->getId();
        $deployment->agent_machine_id = $machine->getId();
        $deployment->system_user = 'agent-guard-test-user';
        $deployment->home_directory = '/home/agent-guard-test-user';
        $deployment->gateway_port = 20000;
        $deployment->proxy_port = 20001;
        $deployment->container_name = 'agent-guard-container';
        $deployment->provider = 'openclaw';
        $deployment->status = 'running';
        $deployment->saveOrFail();

        $snapshot = new AgentUsageSnapshot();
        $snapshot->apps_id = $app->getId();
        $snapshot->companies_id = $company->getId();
        $snapshot->agent_deployment_id = $deployment->getId();
        $snapshot->snapshot_date = now()->toDateString();
        $snapshot->source = 'openclaw_docker';
        $snapshot->input_tokens = 100;
        $snapshot->output_tokens = 50;
        $snapshot->total_tokens = 150;
        $snapshot->cache_read_tokens = 0;
        $snapshot->cache_write_tokens = 0;
        $snapshot->total_sessions = 1;
        $snapshot->raw_output = '{}';
        $snapshot->saveOrFail();

        return $snapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function findDeployment(array $rows, int $snapshotId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $snapshotId) {
                return $row['deployment'];
            }
        }

        $this->fail("Snapshot {$snapshotId} was not returned by agentUsageSnapshots");
    }
}
