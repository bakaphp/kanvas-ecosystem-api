<?php

namespace Tests\GraphQL\Intelligence;

use Tests\TestCase;

class AgentTypeTest extends TestCase
{
    /**
     * Test creating an agent type.
     */
    public function testCreateAgentType()
    {
        $mutation = '
            mutation CreateAgentType($input: AgentTypeInput!) {
                createAgentType(input: $input) {
                    id
                    uuid
                    name
                    description
                    config
                    role
                    is_active
                    is_published
                    is_multi_agent
                    multi_agent_list
                }
            }
        ';

        $variables = [
            'input' => [
                'name' => fake()->name(),
                'description' => fake()->sentence(),
                'role' => 'test-role',
                'config' => '{"key": "value"}',
                'is_active' => fake()->boolean(),
                'is_published' => fake()->boolean(),
                'is_multi_agent' => fake()->boolean(),
                'multi_agent_list' => '{"key": "value"}',
            ],
        ];

        $response = $this->graphQL($mutation, $variables);

        $response->assertJsonFragment([
            'name' => $variables['input']['name'],
            'description' => $variables['input']['description'],
            'role' => $variables['input']['role'],
            'is_active' => $variables['input']['is_active'],
            'is_published' => $variables['input']['is_published'],
            'is_multi_agent' => $variables['input']['is_multi_agent'],
        ]);
    }

    /**
     * Test updating an agent type after creation.
     */
    public function testUpdateAgentType()
    {
        // Create agent type via GraphQL mutation
        $createMutation = '
            mutation CreateAgentType($input: AgentTypeInput!) {
                createAgentType(input: $input) {
                    id
                    name
                    description
                }
            }
        ';

        $createVariables = [
            'input' => [
                'name' => fake()->name(),
                'description' => fake()->sentence(),
                'role' => 'test-role',
                'config' => '{"key": "value"}',
                'is_active' => true,
                'is_published' => false,
                'is_multi_agent' => false,
                'multi_agent_list' => '{"key": "value"}',
            ],
        ];

        $response = $this->graphQL($createMutation, $createVariables);
        $agentTypeId = $response->json('data.createAgentType.id');

        $updateMutation = '
            mutation UpdateAgentType($id: ID!, $input: AgentTypeInput!) {
                updateAgentType(id: $id, input: $input) {
                    id
                    name
                    description
                }
            }
        ';

        $updatedName = fake()->name();
        $updatedDescription = fake()->sentence();

        $updateVariables = [
            'id' => $agentTypeId,
            'input' => [
                'name' => $updatedName,
                'description' => $updatedDescription,
                'role' => 'updated-role',
                'config' => '{"updated": true}',
                'is_active' => false,
                'is_published' => true,
                'is_multi_agent' => true,
                'multi_agent_list' => '{"updated": true}',
            ],
        ];

        $updateResponse = $this->graphQL($updateMutation, $updateVariables);

        $updateResponse->assertJsonFragment([
            'id' => $agentTypeId,
            'name' => $updatedName,
            'description' => $updatedDescription,
        ]);
    }

    /**
     * Test querying agent types and validating that there are rows.
     */
    public function testAgentsTypes()
    {
        $query = '
            query {
                agentTypes {
                    data {
                        id
                        name
                        description
                        role
                        is_active
                        is_published
                        is_multi_agent
                        multi_agent_list
                    }
                }
            }
        ';

        $response = $this->graphQL($query);

        $response->assertJsonStructure([
            'data' => [
                'agentTypes' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'role',
                            'is_active',
                            'is_published',
                            'is_multi_agent',
                            'multi_agent_list',
                        ],
                    ],
                ],
            ],
        ]);

        $data = $response->json('data.agentTypes.data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data, 'Expected at least one agent type in the response.');
    }

    /**
     * Test deleting an agent type after creation.
     */
    public function testDeleteAgentType()
    {
        // Create agent type via GraphQL mutation
        $createMutation = '
            mutation CreateAgentType($input: AgentTypeInput!) {
                createAgentType(input: $input) {
                    id
                    name
                }
            }
        ';

        $createVariables = [
            'input' => [
                'name' => fake()->name(),
                'description' => fake()->sentence(),
                'role' => 'test-role',
                'config' => '{"key": "value"}',
                'is_active' => true,
                'is_published' => false,
                'is_multi_agent' => false,
                'multi_agent_list' => '{"key": "value"}',
            ],
        ];

        $createResponse = $this->graphQL($createMutation, $createVariables);
        $agentTypeId = $createResponse->json('data.createAgentType.id');

        $deleteMutation = '
            mutation DeleteAgentType($id: ID!) {
                deleteAgentType(id: $id)
            }
        ';

        $deleteVariables = [
            'id' => $agentTypeId,
        ];

        $deleteResponse = $this->graphQL($deleteMutation, $deleteVariables);

        $deleteResponse->assertJson([
            'data' => [
                'deleteAgentType' => true,
            ],
        ]);
    }
}
