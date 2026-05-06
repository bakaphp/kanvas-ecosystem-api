<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

class AgentToolsGraphQLTest extends TestCase
{
    private function makeAgentType(): AgentType
    {
        return AgentType::factory()
            ->withAppId(app(Apps::class)->id)
            ->create();
    }

    private function makeTool(array $frameworks = ['laravel']): Tool
    {
        return new CreateToolAction(
            new ToolData(
                app: app(Apps::class),
                name: 'tool-' . uniqid(),
                frameworks: $frameworks,
                toolType: ToolTypeEnum::CUSTOM,
                description: 'Test tool',
            ),
        )->execute();
    }

    public function testAttachToolToAgentTypeReturnsToolWithAgentTypes(): void
    {
        $type = $this->makeAgentType();
        $tool = $this->makeTool();

        $response = $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                attachNervousSystemToolToAgentType(
                    tool_id: $tool_id
                    agent_type_id: $agent_type_id
                ) {
                    id
                    name
                    agentTypes {
                        id
                    }
                }
            }
        ', [
            'tool_id' => $tool->getId(),
            'agent_type_id' => $type->getId(),
        ]);

        $response->assertSuccessful();
        $ids = array_column(
            $response->json('data.attachNervousSystemToolToAgentType.agentTypes'),
            'id'
        );
        $this->assertContains((string) $type->getId(), $ids);
    }

    public function testDetachToolFromAgentTypeReturnsTrue(): void
    {
        $type = $this->makeAgentType();
        $tool = $this->makeTool();

        $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                attachNervousSystemToolToAgentType(tool_id: $tool_id agent_type_id: $agent_type_id) { id }
            }
        ', ['tool_id' => $tool->getId(), 'agent_type_id' => $type->getId()])
            ->assertSuccessful();

        $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                detachNervousSystemToolFromAgentType(tool_id: $tool_id agent_type_id: $agent_type_id)
            }
        ', ['tool_id' => $tool->getId(), 'agent_type_id' => $type->getId()])
            ->assertSuccessful()
            ->assertJson(['data' => ['detachNervousSystemToolFromAgentType' => true]]);

        $this->assertDatabaseMissing(
            'nervous_system_tool_agent_types',
            ['tool_id' => $tool->id, 'agent_type_id' => $type->id],
            'intelligence',
        );
    }

    public function testAgentTypeToolsFieldReturnsAttachedTools(): void
    {
        $type = $this->makeAgentType();
        $toolA = $this->makeTool();
        $toolB = $this->makeTool();

        $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                attachNervousSystemToolToAgentType(tool_id: $tool_id agent_type_id: $agent_type_id) { id }
            }
        ', ['tool_id' => $toolA->getId(), 'agent_type_id' => $type->getId()])->assertSuccessful();

        $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                attachNervousSystemToolToAgentType(tool_id: $tool_id agent_type_id: $agent_type_id) { id }
            }
        ', ['tool_id' => $toolB->getId(), 'agent_type_id' => $type->getId()])->assertSuccessful();

        $response = $this->graphQL('
            query($where: QueryAgentTypesWhereWhereConditions) {
                agentTypes(where: $where) {
                    data {
                        id
                        tools {
                            id
                            name
                        }
                    }
                }
            }
        ', [
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $type->getId()],
        ]);

        $response->assertSuccessful();
        $types = $response->json('data.agentTypes.data');
        $this->assertNotEmpty($types);
        $toolIds = array_column($types[0]['tools'], 'id');
        $this->assertContains((string) $toolA->getId(), $toolIds);
        $this->assertContains((string) $toolB->getId(), $toolIds);
    }

    public function testAgentTypesQueryReturnsGlobalTypes(): void
    {
        $globalType = $this->makeAgentType();
        $globalType->apps_id = 0;
        $globalType->saveOrFail();

        $response = $this->graphQL('
            query($where: QueryAgentTypesWhereWhereConditions) {
                agentTypes(where: $where) {
                    data {
                        id
                    }
                }
            }
        ', [
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $globalType->getId()],
        ]);

        $response->assertSuccessful();
        $ids = array_column($response->json('data.agentTypes.data'), 'id');
        $this->assertContains((string) $globalType->getId(), $ids);
    }

    public function testCreateAgentWithToolIds(): void
    {
        $type = $this->makeAgentType();
        $toolA = $this->makeTool();
        $toolB = $this->makeTool();

        $response = $this->graphQL('
            mutation($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                    selectedTools {
                        id
                        name
                    }
                }
            }
        ', [
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent With Tools ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'tool_ids' => [$toolA->getId(), $toolB->getId()],
            ],
        ]);

        $response->assertSuccessful();
        $toolIds = array_column($response->json('data.createAiAgent.selectedTools'), 'id');
        $this->assertContains((string) $toolA->getId(), $toolIds);
        $this->assertContains((string) $toolB->getId(), $toolIds);
    }

    public function testUpdateAgentSyncsToolIds(): void
    {
        $type = $this->makeAgentType();
        $toolA = $this->makeTool();
        $toolB = $this->makeTool();

        $createResponse = $this->graphQL('
            mutation($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'tool_ids' => [$toolA->getId()],
            ],
        ])->assertSuccessful();

        $agentId = $createResponse->json('data.createAiAgent.id');

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: AgentAiInput!) {
                updateAiAgent(id: $id, input: $input) {
                    id
                    selectedTools {
                        id
                    }
                }
            }
        ', [
            'id' => $agentId,
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent Updated',
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'tool_ids' => [$toolB->getId()],
            ],
        ])->assertSuccessful();

        $toolIds = array_column($updateResponse->json('data.updateAiAgent.selectedTools'), 'id');
        $this->assertNotContains((string) $toolA->getId(), $toolIds, 'toolA should be replaced');
        $this->assertContains((string) $toolB->getId(), $toolIds);
    }

    public function testAgentToolsQueryIncludesAgentTypesField(): void
    {
        $type = $this->makeAgentType();
        $tool = $this->makeTool();

        $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                attachNervousSystemToolToAgentType(tool_id: $tool_id agent_type_id: $agent_type_id) { id }
            }
        ', ['tool_id' => $tool->getId(), 'agent_type_id' => $type->getId()])->assertSuccessful();

        $agentResponse = $this->graphQL('
            mutation($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'tool_ids' => [$tool->getId()],
            ],
        ])->assertSuccessful();

        $agentId = $agentResponse->json('data.createAiAgent.id');

        $response = $this->graphQL('
            query($agent_id: ID!) {
                nervousSystemAgentTools(agent_id: $agent_id) {
                    id
                    name
                    agentTypes {
                        id
                    }
                }
            }
        ', ['agent_id' => $agentId]);

        $response->assertSuccessful();
        $tools = $response->json('data.nervousSystemAgentTools');
        $this->assertNotEmpty($tools);
        $typeIds = array_column($tools[0]['agentTypes'], 'id');
        $this->assertContains((string) $type->getId(), $typeIds);
    }
}
