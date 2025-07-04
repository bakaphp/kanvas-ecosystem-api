<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

class AgentTest extends TestCase
{
    public function createAgentType(): AgentType
    {
        return AgentType::factory()
        ->withAppId(app(Apps::class)->id)
        ->create();
    }

    protected function createAgentModel(): AgentModel
    {
        return AgentModel::factory()
            ->withAppId(app(Apps::class)->id)
            ->create();
    }

    protected function createTaskList(): TaskList
    {
        return TaskList::factory()
            ->withAppId(app(Apps::class)->id)
            ->withCompanyId(auth()->user()->getCurrentCompany()->id)
            ->withUserId(auth()->user()->id)
            ->create();
    }

    public function testCreateAgent()
    {
        $mutation = '
            mutation CreateAgent($input: AgentAiInput!) {
                createAgent(input: $input) {
                    id
                    uuid
                    name
                    description
                    config
                    role
                    is_active
                }
            }';

        $agentTypeId = $this->createAgentType()->getId();
        $agentModel = $this->createAgentModel()->getId();
        $taskListId = $this->createTaskList()->getId();
        $input = [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'description' => 'Test Agent',
                'config' => '{"key": "value"}',
                'name' => 'Test Agent',
                'role' => 'test-role',
                'agent_model_id' => $agentModel,
                'is_active' => true  ,
                'company_task_list_id' => $taskListId ,
            ],
        ];

        $response = $this->graphQL($mutation, $input);
        $response->assertJsonFragment([
            'description' => 'Test Agent',
            'name' => 'Test Agent',
            'role' => 'test-role',
            'is_active' => true,
        ]);
    }

    public function testUpdateAgent()
    {
        $mutation = '
        mutation CreateAgent($input: AgentAiInput!) {
            createAgent(input: $input) {
                id
                uuid
                name
                description
                config
                role
                is_active
            }
        }';

        $agentTypeId = $this->createAgentType()->getId();
        $agentModel = $this->createAgentModel()->getId();

        $taskListId = $this->createTaskList()->getId();

        $input = [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'description' => 'Test Agent',
                'config' => '{"key": "value"}',
                'name' => 'Test Agent',
                'role' => 'test-role',
                'agent_model_id' => $agentModel,
                'is_active' => true  ,
                'company_task_list_id' => $taskListId,
            ],
        ];

        $response = $this->graphQL($mutation, $input);
        $id = $response->json('data.createAgent.id');
        $mutation = '
            mutation UpdateAgent($id: ID!, $input: AgentAiInput!) {
                updateAgent(id: $id, input: $input) {
                    id
                    uuid
                    name
                    description
                    config
                    role
                    is_active
                }
            }';

        $input = [
            'id' => $id,
            'input' => [
                'agent_type_id' => $agentTypeId,
                'description' => 'Updated Test Agent',
                'config' => '{"key": "value"}',
                'name' => 'Updated Test Agent',
                'role' => 'updated-role',
                'agent_model_id' => $agentModel,
                'is_active' => false  ,
                'company_task_list_id' => $taskListId,
            ],
        ];

        $response = $this->graphQL($mutation, $input);
        $response->assertJsonFragment([
            'description' => 'Updated Test Agent',
            'name' => 'Updated Test Agent',
            'role' => 'updated-role',
            'is_active' => false,
        ]);
    }

    public function testGetAgents()
    {
        $mutation = '
        mutation CreateAgent($input: AgentAiInput!) {
            createAgent(input: $input) {
                id
                uuid
                name
                description
                config
                role
                is_active
            }
        }';

        $agentTypeId = $this->createAgentType()->getId();
        $agentModel = $this->createAgentModel()->getId();
        $taskListId = $this->createTaskList()->getId();
        $input = [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'description' => 'Test Agent',
                'config' => '{"key": "value"}',
                'name' => 'Test Agent',
                'role' => 'test-role',
                'agent_model_id' => $agentModel,
                'is_active' => true  ,
                'company_task_list_id' => $taskListId ,
            ],
        ];
        $response = $this->graphQL($mutation, $input);
        $query = '
            query GetAgents {
                agentsAi {
                    data {
                        id
                        uuid
                        name
                        description
                        is_active
                        model {
                            id
                            name
                        }
                        companyTaskList {
                            id
                            name
                        }
                    }
                }
            }';
        $response = $this->graphQL($query);
        $response->assertJsonFragment([
            'name' => 'Test Agent',
            'description' => 'Test Agent',
            'is_active' => true,
        ]);
        $response->assertJsonStructure([
            'data' => [
                'agentsAi' => [
                    'data' => [
                        '*' => [
                            'id',
                            'uuid',
                            'name',
                            'model' => [
                                'id',
                                'name',
                            ],
                            'companyTaskList' => [
                                'id',
                                'name',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
