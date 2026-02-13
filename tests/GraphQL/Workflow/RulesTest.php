<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;
use Throwable;

class RulesTest extends TestCase
{
    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }

    private function getRuleType(): RuleType
    {
        try {
            return RuleType::getByName(WorkflowEnum::CREATED->value);
        } catch (Throwable) {
            return RuleType::factory()->create();
        }
    }

    private function getSystemModuleId(): int
    {
        return SystemModulesRepository::getByModelName(Lead::class)->getId();
    }

    private function getWorkflowAction(): Action
    {
        return Action::first() ?? Action::factory()->create();
    }

    public function testGetRules(): void
    {
        Rule::factory()->create();

        $this->graphQL('
            query {
                rules {
                    data {
                        id
                        name
                        description
                        pattern
                        is_async
                        type {
                            id
                            name
                        }
                        conditions {
                            id
                            attribute_name
                            operator
                            value
                        }
                        workflowActions {
                            id
                            weight
                        }
                        companies_id
                        apps_id
                    }
                }
            }')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['rules' => ['data' => [['id', 'name']]]]]);
    }

    public function testGetRuleTypes(): void
    {
        $this->graphQL('
            query {
                ruleTypes {
                    data {
                        id
                        name
                    }
                }
            }')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['ruleTypes' => ['data' => [['id', 'name']]]]]);
    }

    public function testCreateRule(): void
    {
        $ruleType = $this->getRuleType();
        $systemModuleId = $this->getSystemModuleId();
        $action = $this->getWorkflowAction();

        $input = [
            'name' => 'Test Rule ' . fake()->uuid(),
            'description' => 'Test rule description',
            'rules_types_id' => (string) $ruleType->getId(),
            'systems_modules_id' => (string) $systemModuleId,
            'pattern' => '1',
            'params' => ['key' => 'value'],
            'is_async' => false,
            'conditions' => [
                [
                    'attribute_name' => 'status',
                    'operator' => 'EQUAL',
                    'value' => 'active',
                ],
            ],
            'actions' => [
                [
                    'action_id' => (string) $action->getId(),
                    'weight' => 0,
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: CreateRuleInput!) {
                createRule(input: $input) {
                    id
                    name
                    description
                    pattern
                    is_async
                    type {
                        id
                        name
                    }
                    conditions {
                        id
                        attribute_name
                        operator
                        value
                    }
                    workflowActions {
                        id
                        weight
                    }
                }
            }
        ', ['input' => $input], [], $this->getAppKeyHeader());

        $response->assertSuccessful();

        $data = $response->json('data.createRule');
        $this->assertEquals($input['name'], $data['name']);
        $this->assertEquals($input['description'], $data['description']);
        $this->assertEquals('1', $data['pattern']);
        $this->assertNotEmpty($data['conditions']);
        $this->assertEquals('==', $data['conditions'][0]['operator']);
        $this->assertNotEmpty($data['workflowActions']);
    }

    public function testCreateRuleAutoGeneratesPattern(): void
    {
        $ruleType = $this->getRuleType();
        $systemModuleId = $this->getSystemModuleId();

        $input = [
            'name' => 'Auto Pattern Rule ' . fake()->uuid(),
            'description' => 'Rule with auto-generated pattern',
            'rules_types_id' => (string) $ruleType->getId(),
            'systems_modules_id' => (string) $systemModuleId,
            'conditions' => [
                [
                    'attribute_name' => 'status',
                    'operator' => 'EQUAL',
                    'value' => 'active',
                ],
                [
                    'attribute_name' => 'type',
                    'operator' => 'NOT_EQUAL',
                    'value' => 'draft',
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: CreateRuleInput!) {
                createRule(input: $input) {
                    id
                    name
                    pattern
                    conditions {
                        id
                        attribute_name
                        operator
                    }
                }
            }
        ', ['input' => $input], [], $this->getAppKeyHeader());

        $response->assertSuccessful();

        $data = $response->json('data.createRule');
        $this->assertEquals('1 AND 2', $data['pattern']);
        $this->assertCount(2, $data['conditions']);
    }

    public function testCreateRuleWithCustomPattern(): void
    {
        $ruleType = $this->getRuleType();
        $systemModuleId = $this->getSystemModuleId();

        $input = [
            'name' => 'Custom Pattern Rule ' . fake()->uuid(),
            'rules_types_id' => (string) $ruleType->getId(),
            'systems_modules_id' => (string) $systemModuleId,
            'pattern' => '1 OR 2',
            'conditions' => [
                [
                    'attribute_name' => 'status',
                    'operator' => 'EQUAL',
                    'value' => 'active',
                ],
                [
                    'attribute_name' => 'priority',
                    'operator' => 'GREATER_THAN',
                    'value' => '5',
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: CreateRuleInput!) {
                createRule(input: $input) {
                    id
                    pattern
                    conditions {
                        id
                        operator
                    }
                }
            }
        ', ['input' => $input], [], $this->getAppKeyHeader());

        $response->assertSuccessful();

        $data = $response->json('data.createRule');
        $this->assertEquals('1 OR 2', $data['pattern']);
    }

    public function testCreateRuleWithInvalidPatternFails(): void
    {
        $ruleType = $this->getRuleType();
        $systemModuleId = $this->getSystemModuleId();

        $input = [
            'name' => 'Invalid Pattern Rule ' . fake()->uuid(),
            'rules_types_id' => (string) $ruleType->getId(),
            'systems_modules_id' => (string) $systemModuleId,
            'pattern' => '1 AND 2 AND 3',
            'conditions' => [
                [
                    'attribute_name' => 'status',
                    'operator' => 'EQUAL',
                    'value' => 'active',
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: CreateRuleInput!) {
                createRule(input: $input) {
                    id
                    pattern
                }
            }
        ', ['input' => $input], [], $this->getAppKeyHeader());

        $response->assertGraphQLValidationError('pattern', 'Pattern must reference exactly conditions 1, got: 1, 2, 3');
    }

    public function testUpdateRule(): void
    {
        $rule = Rule::factory()->create();

        $input = [
            'name' => 'Updated Rule ' . fake()->uuid(),
            'description' => 'Updated description',
            'conditions' => [
                [
                    'attribute_name' => 'type',
                    'operator' => 'NOT_EQUAL',
                    'value' => 'draft',
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($id: ID!, $input: UpdateRuleInput!) {
                updateRule(id: $id, input: $input) {
                    id
                    name
                    description
                    pattern
                    conditions {
                        id
                        attribute_name
                        operator
                        value
                    }
                }
            }
        ', [
            'id' => $rule->getId(),
            'input' => $input,
        ], [], $this->getAppKeyHeader());

        $response->assertSuccessful();

        $data = $response->json('data.updateRule');
        $this->assertEquals($input['name'], $data['name']);
        $this->assertEquals($input['description'], $data['description']);
        $this->assertEquals('1', $data['pattern']);
        $this->assertNotEmpty($data['conditions']);
        $this->assertEquals('type', $data['conditions'][0]['attribute_name']);
        $this->assertEquals('!=', $data['conditions'][0]['operator']);
    }

    public function testDeleteRule(): void
    {
        $rule = Rule::factory()->create();

        $response = $this->graphQL('
            mutation($id: ID!) {
                deleteRule(id: $id)
            }
        ', ['id' => $rule->getId()], [], $this->getAppKeyHeader());

        $response->assertSuccessful()
            ->assertJson(['data' => ['deleteRule' => true]]);

        $deletedRule = Rule::find($rule->getId());
        $this->assertEquals(1, $deletedRule->is_deleted);
    }
}
