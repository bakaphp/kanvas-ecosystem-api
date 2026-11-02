<?php

namespace Tests\GraphQL\Intelligence;

use Tests\TestCase;

class AgentTypeTest extends TestCase
{
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
     * Agent types are the platform catalog, written by `kanvas:intelligence:sync-agent-types` or by
     * hand in the database: their handler is a class every agent of the type builds.
     */
    public function testAgentTypesCannotBeWrittenThroughGraphQL(): void
    {
        foreach ([
            'mutation { createAgentType(input: {name: "x"}) { id } }',
            'mutation { updateAgentType(id: 1, input: {name: "x"}) { id } }',
            'mutation { deleteAgentType(id: 1) }',
        ] as $mutation) {
            $this->assertStringContainsString(
                'Cannot query field',
                (string) $this->graphQL($mutation)->json('errors.0.message'),
                $mutation
            );
        }
    }
}
