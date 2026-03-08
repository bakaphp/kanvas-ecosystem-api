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
                        data {
                            id
                            name
                        }
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
                        data {
                            id
                        }
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
