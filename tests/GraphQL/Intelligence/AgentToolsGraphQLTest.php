<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\AttachToolToAgentTypeAction;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;
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

    public function testCreateAgentWithGlobalToolId(): void
    {
        $type = $this->makeAgentType();
        $globalTool = $this->makeTool();
        $globalTool->apps_id = 0;
        $globalTool->saveOrFail();

        $response = $this->graphQL('
            mutation($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                    selectedTools {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent Global Tool ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'tool_ids' => [$globalTool->getId()],
            ],
        ]);

        $response->assertSuccessful();
        $toolIds = array_column($response->json('data.createAiAgent.selectedTools'), 'id');
        $this->assertContains(
            (string) $globalTool->getId(),
            $toolIds,
            'Global (apps_id=0) tools must be attachable to an agent',
        );
    }

    public function testSetAgentToolWithGlobalToolGrants(): void
    {
        $type = $this->makeAgentType();
        $globalTool = $this->makeTool();
        $globalTool->apps_id = 0;
        $globalTool->saveOrFail();

        $agentResponse = $this->graphQL('
            mutation($input: AgentAiInput!) {
                createAiAgent(input: $input) { id }
            }
        ', [
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent Grant ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
            ],
        ])->assertSuccessful();

        $agentId = $agentResponse->json('data.createAiAgent.id');

        $response = $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!, $enabled: Boolean!) {
                setNervousSystemAgentTool(
                    agent_id: $agent_id
                    tool_id: $tool_id
                    enabled: $enabled
                ) {
                    id
                    is_active
                    is_deleted
                    tool { id }
                }
            }
        ', [
            'agent_id' => $agentId,
            'tool_id' => $globalTool->getId(),
            'enabled' => true,
        ]);

        $response->assertSuccessful();
        $this->assertSame(
            (string) $globalTool->getId(),
            $response->json('data.setNervousSystemAgentTool.tool.id'),
            'Global tools must be grantable via setNervousSystemAgentTool',
        );
        $this->assertTrue($response->json('data.setNervousSystemAgentTool.is_active'));
        $this->assertFalse($response->json('data.setNervousSystemAgentTool.is_deleted'));
    }

    public function testAttachGlobalToolToAgentType(): void
    {
        $type = $this->makeAgentType();
        $globalTool = $this->makeTool();
        $globalTool->apps_id = 0;
        $globalTool->saveOrFail();

        $response = $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                attachNervousSystemToolToAgentType(
                    tool_id: $tool_id
                    agent_type_id: $agent_type_id
                ) { id }
            }
        ', [
            'tool_id' => $globalTool->getId(),
            'agent_type_id' => $type->getId(),
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas(
            'nervous_system_tool_agent_types',
            ['tool_id' => $globalTool->getId(), 'agent_type_id' => $type->getId()],
            'intelligence',
        );
    }

    public function testDetachGlobalToolFromAgentType(): void
    {
        $type = $this->makeAgentType();
        $globalTool = $this->makeTool();
        $globalTool->apps_id = 0;
        $globalTool->saveOrFail();

        new AttachToolToAgentTypeAction($globalTool, $type)->execute();

        $response = $this->graphQL('
            mutation($tool_id: ID!, $agent_type_id: ID!) {
                detachNervousSystemToolFromAgentType(
                    tool_id: $tool_id
                    agent_type_id: $agent_type_id
                )
            }
        ', [
            'tool_id' => $globalTool->getId(),
            'agent_type_id' => $type->getId(),
        ]);

        $response->assertSuccessful()->assertJson([
            'data' => ['detachNervousSystemToolFromAgentType' => true],
        ]);

        $this->assertDatabaseMissing(
            'nervous_system_tool_agent_types',
            ['tool_id' => $globalTool->getId(), 'agent_type_id' => $type->getId()],
            'intelligence',
        );
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

    public function testNervousSystemToolsListIncludesGlobalTool(): void
    {
        $globalTool = $this->makeTool();
        $globalTool->apps_id = 0;
        $globalTool->saveOrFail();

        $response = $this->graphQL('
            query($where: QueryNervousSystemToolsWhereWhereConditions) {
                nervousSystemTools(where: $where) {
                    data {
                        id
                    }
                }
            }
        ', [
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $globalTool->getId()],
        ]);

        $response->assertSuccessful();
        $ids = array_column($response->json('data.nervousSystemTools.data'), 'id');
        $this->assertContains(
            (string) $globalTool->getId(),
            $ids,
            'Global (apps_id=0) tools must appear in nervousSystemTools',
        );
    }

    public function testAgentToolsIncludesGlobalTypeTool(): void
    {
        $type = $this->makeAgentType();
        $globalTool = $this->makeTool();
        $globalTool->apps_id = 0;
        $globalTool->saveOrFail();

        new AttachToolToAgentTypeAction($globalTool, $type)->execute();

        $agentResponse = $this->graphQL('
            mutation($input: AgentAiInput!) {
                createAiAgent(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_type_id' => $type->getId(),
                'name' => 'Agent Global Type Tool ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
            ],
        ])->assertSuccessful();

        $agentId = $agentResponse->json('data.createAiAgent.id');

        $response = $this->graphQL('
            query($agent_id: ID!) {
                nervousSystemAgentTools(agent_id: $agent_id) {
                    id
                }
            }
        ', ['agent_id' => $agentId]);

        $response->assertSuccessful();
        $ids = array_column($response->json('data.nervousSystemAgentTools'), 'id');
        $this->assertContains(
            (string) $globalTool->getId(),
            $ids,
            'A global tool attached to the agent type must appear in nervousSystemAgentTools',
        );
    }

    public function testToolCategoriesFiltersByFramework(): void
    {
        $laravelCategory = $this->makeCategory();
        $neuronCategory = $this->makeCategory();

        $laravelTool = $this->makeTool(['laravel']);
        $laravelTool->tool_category_id = $laravelCategory->getId();
        $laravelTool->saveOrFail();

        $neuronTool = $this->makeTool(['neuron']);
        $neuronTool->tool_category_id = $neuronCategory->getId();
        $neuronTool->saveOrFail();

        $query = '
            query($framework: String, $where: QueryNervousSystemToolCategoriesWhereWhereConditions) {
                nervousSystemToolCategories(framework: $framework, where: $where) {
                    data { id }
                }
            }
        ';

        $hit = $this->graphQL($query, [
            'framework' => 'laravel',
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $laravelCategory->getId()],
        ])->assertSuccessful();

        $this->assertSame(
            [(string) $laravelCategory->getId()],
            array_column($hit->json('data.nervousSystemToolCategories.data'), 'id'),
            'Category whose tools include the framework must appear',
        );

        $miss = $this->graphQL($query, [
            'framework' => 'laravel',
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $neuronCategory->getId()],
        ])->assertSuccessful();

        $this->assertSame(
            [],
            array_column($miss->json('data.nervousSystemToolCategories.data'), 'id'),
            'Category with only non-matching tools must be filtered out',
        );
    }

    public function testToolCategoriesFiltersByAgentTypeId(): void
    {
        $type = $this->makeAgentType();
        $type->provider = 'laravel';
        $type->saveOrFail();

        $laravelCategory = $this->makeCategory();
        $neuronCategory = $this->makeCategory();

        $laravelTool = $this->makeTool(['laravel']);
        $laravelTool->tool_category_id = $laravelCategory->getId();
        $laravelTool->saveOrFail();

        $neuronTool = $this->makeTool(['neuron']);
        $neuronTool->tool_category_id = $neuronCategory->getId();
        $neuronTool->saveOrFail();

        $query = '
            query($agent_type_id: ID, $where: QueryNervousSystemToolCategoriesWhereWhereConditions) {
                nervousSystemToolCategories(agent_type_id: $agent_type_id, where: $where) {
                    data { id }
                }
            }
        ';

        $hit = $this->graphQL($query, [
            'agent_type_id' => $type->getId(),
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $laravelCategory->getId()],
        ])->assertSuccessful();

        $this->assertSame(
            [(string) $laravelCategory->getId()],
            array_column($hit->json('data.nervousSystemToolCategories.data'), 'id'),
            'Category with a laravel tool must appear when agent_type.provider=laravel',
        );

        $miss = $this->graphQL($query, [
            'agent_type_id' => $type->getId(),
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $neuronCategory->getId()],
        ])->assertSuccessful();

        $this->assertSame(
            [],
            array_column($miss->json('data.nervousSystemToolCategories.data'), 'id'),
            'Category with only neuron tools must NOT appear for a laravel agent type',
        );
    }

    public function testNervousSystemToolsFiltersByAgentTypeId(): void
    {
        $type = $this->makeAgentType();
        $type->provider = 'laravel';
        $type->saveOrFail();

        $laravelTool = $this->makeTool(['laravel']);
        $laravelTool->apps_id = 0;
        $laravelTool->saveOrFail();

        $neuronTool = $this->makeTool(['neuron']);
        $neuronTool->apps_id = 0;
        $neuronTool->saveOrFail();

        $query = '
            query($agent_type_id: ID, $where: QueryNervousSystemToolsWhereWhereConditions) {
                nervousSystemTools(agent_type_id: $agent_type_id, where: $where) {
                    data { id }
                }
            }
        ';

        $hit = $this->graphQL($query, [
            'agent_type_id' => $type->getId(),
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $laravelTool->getId()],
        ])->assertSuccessful();

        $this->assertSame(
            [(string) $laravelTool->getId()],
            array_column($hit->json('data.nervousSystemTools.data'), 'id'),
            'Tool matching the agent type framework must appear',
        );

        $miss = $this->graphQL($query, [
            'agent_type_id' => $type->getId(),
            'where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $neuronTool->getId()],
        ])->assertSuccessful();

        $this->assertSame(
            [],
            array_column($miss->json('data.nervousSystemTools.data'), 'id'),
            'Tool from a different framework than the agent type must NOT appear',
        );
    }

    private function makeCategory(): ToolCategory
    {
        $cat = new ToolCategory();
        $cat->slug = 'fw-test-' . uniqid();
        $cat->name = 'Test Category ' . uniqid();
        $cat->description = '';
        $cat->icon = 'tag';
        $cat->display_order = 999;
        $cat->is_active = true;
        $cat->is_deleted = false;
        $cat->saveOrFail();

        return $cat;
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
