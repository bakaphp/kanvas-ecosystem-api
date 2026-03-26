<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

class AgentSwarmCrudTest extends TestCase
{
    protected function createAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->id)
            ->create();

        $agentModel = AgentModel::factory()
            ->withAppId($app->id)
            ->create();

        return Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $agentType->id,
                'agent_model_id' => $agentModel->id,
            ]);
    }

    public function testCreateAgentSwarm(): void
    {
        $input = [
            'name' => 'Test Swarm ' . fake()->word(),
            'description' => 'A test swarm for organizing agents',
            'status' => 'draft',
        ];

        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) {
                    id
                    name
                    slug
                    description
                    status
                    agent_count
                    deployment_status
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAgentSwarm' => [
                    'name' => $input['name'],
                    'status' => 'draft',
                    'agent_count' => 0,
                    'deployment_status' => 'pending',
                ],
            ],
        ]);
    }

    public function testCreateAgentSwarmWithAgents(): void
    {
        $agent1 = $this->createAgent();
        $agent2 = $this->createAgent();

        $input = [
            'name' => 'Team Swarm ' . fake()->word(),
            'description' => 'Swarm with agents',
            'status' => 'active',
            'agent_ids' => [(string) $agent1->getId(), (string) $agent2->getId()],
        ];

        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) {
                    id
                    name
                    status
                    agent_count
                    agents {
                        id
                        name
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAgentSwarm' => [
                    'name' => $input['name'],
                    'status' => 'active',
                    'agent_count' => 2,
                ],
            ],
        ]);
    }

    public function testUpdateAgentSwarm(): void
    {
        $input = ['name' => 'Original Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id name }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createAgentSwarm.id');

        $updateInput = [
            'name' => 'Updated Swarm ' . fake()->word(),
            'status' => 'active',
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAgentSwarmInput!) {
                updateAgentSwarm(id: $id, input: $input) {
                    id
                    name
                    status
                }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAgentSwarm' => [
                    'name' => $updateInput['name'],
                    'status' => 'active',
                ],
            ],
        ]);
    }

    public function testDeleteAgentSwarm(): void
    {
        $input = ['name' => 'Delete Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createAgentSwarm.id');

        $this->graphQL('
            mutation($id: ID!) {
                deleteAgentSwarm(id: $id)
            }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAgentSwarm' => true]]);
    }

    public function testAddAgentToSwarm(): void
    {
        $input = ['name' => 'Add Agent Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');
        $agent = $this->createAgent();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id) {
                    id
                    agent_count
                    agents {
                        id
                    }
                }
            }
        ', ['swarm_id' => $swarmId, 'agent_id' => (string) $agent->getId()])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'addAgentToSwarm' => [
                    'agent_count' => 1,
                ],
            ],
        ]);
    }

    public function testRemoveAgentFromSwarm(): void
    {
        $agent = $this->createAgent();

        $input = [
            'name' => 'Remove Agent Swarm ' . fake()->word(),
            'agent_ids' => [(string) $agent->getId()],
        ];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id agent_count }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!) {
                removeAgentFromSwarm(swarm_id: $swarm_id, agent_id: $agent_id) {
                    id
                    agent_count
                }
            }
        ', ['swarm_id' => $swarmId, 'agent_id' => (string) $agent->getId()])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'removeAgentFromSwarm' => [
                    'agent_count' => 0,
                ],
            ],
        ]);
    }

    public function testAddAgentToSwarmWithRole(): void
    {
        $input = ['name' => 'Role Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');
        $agent = $this->createAgent();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) {
                    id
                    agent_count
                    members {
                        id
                        role
                        agent {
                            id
                        }
                    }
                }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $agent->getId(),
            'role' => 'manager',
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'addAgentToSwarm' => [
                    'agent_count' => 1,
                ],
            ],
        ]);
    }

    public function testAddAgentWithReportsTo(): void
    {
        $input = ['name' => 'Hierarchy Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');

        $manager = $this->createAgent();
        $subordinate = $this->createAgent();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) {
                    id
                }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $manager->getId(),
            'role' => 'manager',
        ])->assertSuccessful();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String, $reports_to_agent_id: ID) {
                addAgentToSwarm(
                    swarm_id: $swarm_id
                    agent_id: $agent_id
                    role: $role
                    reports_to_agent_id: $reports_to_agent_id
                ) {
                    id
                    members {
                        id
                        role
                        agent {
                            id
                        }
                        reports_to {
                            id
                            agent {
                                id
                            }
                        }
                    }
                }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $subordinate->getId(),
            'role' => 'worker',
            'reports_to_agent_id' => (string) $manager->getId(),
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'addAgentToSwarm' => [
                    'id' => $swarmId,
                ],
            ],
        ]);
    }

    public function testUpdateSwarmMemberRole(): void
    {
        $input = ['name' => 'Update Member Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');
        $agent = $this->createAgent();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) { id }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $agent->getId(),
            'role' => 'worker',
        ])->assertSuccessful();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                updateSwarmMember(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) {
                    id
                    role
                    agent {
                        id
                    }
                }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $agent->getId(),
            'role' => 'lead',
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateSwarmMember' => [
                    'role' => 'lead',
                ],
            ],
        ]);
    }

    public function testUpdateSwarmMemberReportsTo(): void
    {
        $input = ['name' => 'ReportsTo Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');

        $manager = $this->createAgent();
        $worker = $this->createAgent();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) { id }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $manager->getId(),
            'role' => 'manager',
        ])->assertSuccessful();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) { id }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $worker->getId(),
            'role' => 'worker',
        ])->assertSuccessful();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $reports_to_agent_id: ID) {
                updateSwarmMember(
                    swarm_id: $swarm_id
                    agent_id: $agent_id
                    reports_to_agent_id: $reports_to_agent_id
                ) {
                    id
                    role
                    reports_to {
                        id
                        agent {
                            id
                        }
                    }
                }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $worker->getId(),
            'reports_to_agent_id' => (string) $manager->getId(),
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateSwarmMember' => [
                    'role' => 'worker',
                ],
            ],
        ]);
    }

    public function testSwarmMembersOrgChart(): void
    {
        $input = ['name' => 'OrgChart Swarm ' . fake()->word()];

        $createResponse = $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => $input])->assertSuccessful();

        $swarmId = $createResponse->json('data.createAgentSwarm.id');

        $ceo = $this->createAgent();
        $vp = $this->createAgent();
        $worker = $this->createAgent();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String) {
                addAgentToSwarm(swarm_id: $swarm_id, agent_id: $agent_id, role: $role) { id }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $ceo->getId(),
            'role' => 'ceo',
        ])->assertSuccessful();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String, $reports_to_agent_id: ID) {
                addAgentToSwarm(
                    swarm_id: $swarm_id
                    agent_id: $agent_id
                    role: $role
                    reports_to_agent_id: $reports_to_agent_id
                ) { id }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $vp->getId(),
            'role' => 'vp',
            'reports_to_agent_id' => (string) $ceo->getId(),
        ])->assertSuccessful();

        $this->graphQL('
            mutation($swarm_id: ID!, $agent_id: ID!, $role: String, $reports_to_agent_id: ID) {
                addAgentToSwarm(
                    swarm_id: $swarm_id
                    agent_id: $agent_id
                    role: $role
                    reports_to_agent_id: $reports_to_agent_id
                ) { id }
            }
        ', [
            'swarm_id' => $swarmId,
            'agent_id' => (string) $worker->getId(),
            'role' => 'worker',
            'reports_to_agent_id' => (string) $vp->getId(),
        ])->assertSuccessful();

        $response = $this->graphQL('
            query {
                agentSwarms(where: { column: ID, operator: EQ, value: "' . $swarmId . '" }) {
                    data {
                        id
                        members {
                            id
                            role
                            agent {
                                id
                                name
                            }
                            reports_to {
                                id
                                role
                            }
                            direct_reports {
                                id
                                role
                            }
                        }
                    }
                }
            }
        ')
        ->assertSuccessful();

        $members = $response->json('data.agentSwarms.data.0.members');
        $this->assertCount(3, $members);
    }

    public function testCreateDuplicateAgentSwarmSlug(): void
    {
        $name = 'Duplicate Swarm ' . fake()->word();

        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => ['name' => $name]])->assertSuccessful();

        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => ['name' => $name]])
        ->assertGraphQLErrorMessage("An agent swarm with the name '{$name}' already exists");
    }

    public function testAgentSwarmIsActiveFromStatus(): void
    {
        $input = [
            'name' => 'Active Swarm ' . fake()->word(),
            'status' => 'active',
        ];

        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) {
                    id
                    status
                    is_active
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAgentSwarm' => [
                    'status' => 'active',
                    'is_active' => true,
                ],
            ],
        ]);

        $draftInput = [
            'name' => 'Draft Swarm ' . fake()->word(),
            'status' => 'draft',
        ];

        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) {
                    id
                    status
                    is_active
                }
            }
        ', ['input' => $draftInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAgentSwarm' => [
                    'status' => 'draft',
                    'is_active' => false,
                ],
            ],
        ]);
    }

    public function testListAgentSwarms(): void
    {
        $this->graphQL('
            mutation($input: AgentSwarmInput!) {
                createAgentSwarm(input: $input) { id }
            }
        ', ['input' => ['name' => 'List Test ' . fake()->word()]])
        ->assertSuccessful();

        $this->graphQL('
            query {
                agentSwarms {
                    data {
                        id
                        name
                        slug
                        status
                        agent_count
                        deployment_status
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'agentSwarms' => [
                    'data' => [
                        ['id', 'name', 'slug', 'status', 'agent_count', 'deployment_status'],
                    ],
                ],
            ],
        ]);
    }
}
