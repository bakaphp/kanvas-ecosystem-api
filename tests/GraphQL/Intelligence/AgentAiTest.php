<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

class AgentAiTest extends TestCase
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
                createAiAgent(input: $input) {
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
                'config' => [
                    "key" => "value",
                ],
                'name' => 'Test Agent',
                'role' => [
                    'name' => 'test-role',
                    'description' => 'This is a test role',
                ],
                'agent_model_id' => $agentModel,
                'is_active' => true  ,
                'company_task_list_id' => $taskListId,
                'communication_channels' => [
                    [
                        'communication_channel_id' => 1,
                        'entry_point' => 'test',
                        'config' => json_encode(['key' => 'value']),
                    ],
                ],
            ],
        ];

        $response = $this->graphQL($mutation, $input);
        $response->assertJsonFragment([
            'description' => 'Test Agent',
            'name' => 'Test Agent',
            'role' => [
                'name' => 'test-role',
                'description' => 'This is a test role',
            ],
            'is_active' => true,
        ]);
    }

    public function testUpdateAgent()
    {
        $mutation = '
        mutation CreateAgent($input: AgentAiInput!) {
            createAiAgent(input: $input) {
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
        $id = $response->json('data.createAiAgent.id');
        $mutation = '
            mutation UpdateAgent($id: ID!, $input: AgentAiInput!) {
                updateAiAgent(id: $id, input: $input) {
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

    public function testCreateAgentWithNewFields()
    {
        $mutation = '
            mutation CreateAgent($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                    uuid
                    name
                    description
                    soul
                    instructions
                    output_format
                    identity
                    user_context
                    tools_config
                    deployment_status
                    is_active
                }
            }';

        $agentTypeId = $this->createAgentType()->getId();
        $agentModel = $this->createAgentModel()->getId();
        $taskListId = $this->createTaskList()->getId();

        $input = [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'name' => 'OpenClaw Test Agent',
                'description' => 'An agent with all new fields',
                'config' => ['key' => 'value'],
                'role' => ['name' => 'test-role'],
                'agent_model_id' => $agentModel,
                'is_active' => true,
                'company_task_list_id' => $taskListId,
                'soul' => 'You are a helpful sales assistant.',
                'instructions' => 'Step 1: Greet the user. Step 2: Ask about needs.',
                'output_format' => 'Always respond in JSON format.',
                'identity' => ['name' => 'SalesBot', 'emoji' => '🤖', 'vibe' => 'professional'],
                'user_context' => 'The user is a potential customer.',
                'tools_config' => 'Use CRM lookup for customer info.',
            ],
        ];

        $response = $this->graphQL($mutation, $input);
        $response->assertJsonFragment([
            'name' => 'OpenClaw Test Agent',
            'soul' => 'You are a helpful sales assistant.',
            'instructions' => 'Step 1: Greet the user. Step 2: Ask about needs.',
            'output_format' => 'Always respond in JSON format.',
            'identity' => ['name' => 'SalesBot', 'emoji' => '🤖', 'vibe' => 'professional'],
            'user_context' => 'The user is a potential customer.',
            'tools_config' => 'Use CRM lookup for customer info.',
            'deployment_status' => 'pending',
        ]);
    }

    public function testCreateAgentWithParent()
    {
        $mutation = '
            mutation CreateAgent($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                    name
                    parent_id
                    parent {
                        id
                        name
                    }
                }
            }';

        $agentTypeId = $this->createAgentType()->getId();

        $parentInput = [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'name' => 'Parent Orchestrator',
                'description' => 'Root agent',
                'config' => '{}',
                'role' => 'orchestrator',
                'is_active' => true,
            ],
        ];

        $parentResponse = $this->graphQL($mutation, $parentInput);
        $parentId = $parentResponse->json('data.createAiAgent.id');

        $childInput = [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'name' => 'Child Specialist',
                'description' => 'Sub-agent',
                'config' => '{}',
                'role' => 'specialist',
                'is_active' => true,
                'parent_agent_id' => $parentId,
            ],
        ];

        $childResponse = $this->graphQL($mutation, $childInput);
        $childResponse->assertJsonFragment([
            'name' => 'Child Specialist',
            'parent_id' => $parentId,
        ]);
        $childResponse->assertJsonFragment([
            'name' => 'Parent Orchestrator',
        ]);
    }

    public function testGetAgentWithChildren()
    {
        $createMutation = '
            mutation CreateAgent($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                    name
                }
            }';

        $agentTypeId = $this->createAgentType()->getId();

        $parentResponse = $this->graphQL($createMutation, [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'name' => 'Swarm Leader',
                'description' => 'Root',
                'config' => '{}',
                'role' => 'leader',
                'is_active' => true,
            ],
        ]);
        $parentId = $parentResponse->json('data.createAiAgent.id');

        $this->graphQL($createMutation, [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'name' => 'Worker A',
                'description' => 'Worker',
                'config' => '{}',
                'role' => 'worker',
                'is_active' => true,
                'parent_agent_id' => $parentId,
            ],
        ]);

        $this->graphQL($createMutation, [
            'input' => [
                'agent_type_id' => $agentTypeId,
                'name' => 'Worker B',
                'description' => 'Worker',
                'config' => '{}',
                'role' => 'worker',
                'is_active' => true,
                'parent_agent_id' => $parentId,
            ],
        ]);

        $query = '
            query GetAgents($where: QueryAgentsAiWhereWhereConditions) {
                agentsAi(where: $where) {
                    data {
                        id
                        name
                        children {
                            id
                            name
                        }
                    }
                }
            }';

        $response = $this->graphQL($query, [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $parentId,
            ],
        ]);

        $response->assertSuccessful();
        $agents = $response->json('data.agentsAi.data');
        $this->assertNotEmpty($agents, 'Expected the parent agent in response');
        $data = $agents[0];
        $this->assertEquals('Swarm Leader', $data['name']);
        $this->assertCount(2, $data['children']);
    }

    public function testGetAgents()
    {
        $mutation = '
        mutation CreateAgent($input: AgentAiInput!) {
            createAiAgent(input: $input) {
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
