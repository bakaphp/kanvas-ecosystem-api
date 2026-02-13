<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Illuminate\Support\Facades\Auth;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class RuleCrudTest extends TestCase
{
    protected $apps;
    protected $user;
    protected $company;
    protected SystemModules $systemModule;
    protected RuleType $ruleType;
    protected RuleWorkflowAction $workflowAction;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();

        $scope = RolesEnums::getScope($this->apps, global: false);
        Bouncer::scope()->to($scope);
        Bouncer::assign('Admins')->to($this->user);
        Bouncer::allow('Admins')->to(['create', 'edit', 'delete'], Rule::class);

        $this->systemModule = SystemModules::firstOrCreate([
            'slug' => 'test-module-' . uniqid(),
        ], [
            'apps_id' => $this->apps->getId(),
            'name' => 'Test Module',
            'model_name' => 'TestModel',
            'parents_id' => 0,
            'show' => 1,
        ]);

        $this->ruleType = RuleType::firstOrCreate([
            'name' => 'CREATED',
        ]);

        $action = WorkflowAction::firstOrCreate([
            'name' => 'Test Action',
            'model_name' => 'TestAction',
        ]);

        $this->workflowAction = RuleWorkflowAction::firstOrCreate([
            'system_modules_id' => $this->systemModule->getId(),
            'actions_id' => $action->getId(),
        ]);
    }

    public function testCreateRuleWithConditionsAndActions(): void
    {
        $input = [
            'name' => 'Test Rule ' . fake()->word(),
            'description' => 'Test rule description',
            'rules_types_id' => $this->ruleType->getId(),
            'pattern' => '1 AND 2',
            'params' => ['key' => 'value'],
            'is_async' => true,
            'conditions' => [
                ['attribute_name' => 'status_id', 'operator' => '==', 'value' => '1', 'is_custom_attributes' => false],
                ['attribute_name' => 'total', 'operator' => '>', 'value' => '100', 'is_custom_attributes' => false],
            ],
            'actions' => [
                ['rules_workflow_actions_id' => $this->workflowAction->getId(), 'weight' => 0],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: RuleInput!) {
                createRule(input: $input) {
                    id
                    name
                    description
                    pattern
                    params
                    is_async
                    system_module {
                        id
                        name
                    }
                    rule_type {
                        id
                        name
                    }
                    conditions {
                        attribute_name
                        operator
                        value
                    }
                    actions {
                        weight
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createRule' => [
                    'name' => $input['name'],
                    'description' => $input['description'],
                    'pattern' => '1 AND 2',
                    'is_async' => true,
                ],
            ],
        ]);

        $ruleId = $response->json('data.createRule.id');
        $this->assertDatabaseHas('rules', [
            'id' => $ruleId,
            'name' => $input['name'],
        ], 'workflow');
        $this->assertDatabaseHas('rules_conditions', ['rules_id' => $ruleId], 'workflow');
        $this->assertDatabaseHas('rules_actions', ['rules_id' => $ruleId], 'workflow');
    }

    public function testUpdateRuleConditionsAndActions(): void
    {
        $rule = Rule::factory()->create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'systems_modules_id' => $this->systemModule->getId(),
            'rules_types_id' => $this->ruleType->getId(),
            'name' => 'Original Rule',
            'pattern' => '1 OR 2',
        ]);

        $updateInput = [
            'name' => 'Updated Rule ' . fake()->word(),
            'description' => 'Updated description',
            'pattern' => '1 AND 2 AND 3',
            'conditions' => [
                ['attribute_name' => 'new_field', 'operator' => '!=', 'value' => 'test', 'is_custom_attributes' => false],
            ],
            'actions' => [
                ['rules_workflow_actions_id' => $this->workflowAction->getId(), 'weight' => 1],
            ],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateRuleInput!) {
                updateRule(id: $id, input: $input) {
                    id
                    name
                    description
                    pattern
                    conditions {
                        attribute_name
                    }
                    actions {
                        weight
                    }
                }
            }
        ', [
            'id' => $rule->getId(),
            'input' => $updateInput,
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateRule' => [
                    'name' => $updateInput['name'],
                    'description' => $updateInput['description'],
                    'pattern' => '1 AND 2 AND 3',
                ],
            ],
        ]);

        $this->assertDatabaseHas('rules', [
            'id' => $rule->getId(),
            'name' => $updateInput['name'],
        ], 'workflow');
    }

    public function testDeleteRule(): void
    {
        $rule = Rule::factory()->create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'systems_modules_id' => $this->systemModule->getId(),
            'rules_types_id' => $this->ruleType->getId(),
        ]);

        $this->graphQL('
            mutation($id: ID!) {
                deleteRule(id: $id)
            }
        ', ['id' => $rule->getId()])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteRule' => true,
            ],
        ]);

        $this->assertDatabaseHas('rules', [
            'id' => $rule->getId(),
            'is_deleted' => 1,
        ], 'workflow');
    }

    public function testListRules(): void
    {
        Rule::factory()->count(3)->create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'systems_modules_id' => $this->systemModule->getId(),
            'rules_types_id' => $this->ruleType->getId(),
        ]);

        $this->graphQL('
            query {
                workflowRules {
                    data {
                        id
                        name
                        pattern
                        is_async
                        system_module {
                            id
                            name
                        }
                        rule_type {
                            id
                            name
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'workflowRules' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'pattern',
                            'is_async',
                            'system_module',
                            'rule_type',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testListRuleTypes(): void
    {
        $this->graphQL('
            query {
                ruleTypes {
                    data {
                        id
                        name
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'ruleTypes' => [
                    'data' => [
                        '*' => ['id', 'name'],
                    ],
                ],
            ],
        ]);
    }

    public function testSearchRules(): void
    {
        $uniqueName = 'SearchableRule' . fake()->uuid();

        Rule::factory()->create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'systems_modules_id' => $this->systemModule->getId(),
            'rules_types_id' => $this->ruleType->getId(),
            'name' => $uniqueName,
        ]);

        $this->graphQL('
            query($search: String) {
                workflowRules(search: $search) {
                    data {
                        id
                        name
                    }
                }
            }
        ', ['search' => $uniqueName])
        ->assertSuccessful();
    }

    public function testListWorkflowActions(): void
    {
        $this->graphQL('
            query {
                workflowActions {
                    data {
                        id
                        name
                        model_name
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'workflowActions' => [
                    'data' => [
                        '*' => ['id', 'name', 'model_name'],
                    ],
                ],
            ],
        ]);
    }

    public function testListRuleWorkflowActions(): void
    {
        $this->graphQL('
            query {
                ruleWorkflowActions {
                    data {
                        id
                        action_name
                        action_class
                        system_module {
                            id
                            name
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'ruleWorkflowActions' => [
                    'data' => [
                        '*' => ['id', 'action_name', 'action_class'],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateRuleWithMismatchedActionsModulesFails(): void
    {
        $otherModule = SystemModules::firstOrCreate([
            'slug' => 'other-module-' . uniqid(),
        ], [
            'apps_id' => $this->apps->getId(),
            'name' => 'Other Module',
            'model_name' => 'OtherModel',
            'parents_id' => 0,
            'show' => 1,
        ]);

        $action = WorkflowAction::firstOrCreate([
            'name' => 'Other Action',
            'model_name' => 'OtherAction',
        ]);

        $otherWorkflowAction = RuleWorkflowAction::firstOrCreate([
            'system_modules_id' => $otherModule->getId(),
            'actions_id' => $action->getId(),
        ]);

        $input = [
            'name' => 'Test Rule ' . fake()->word(),
            'rules_types_id' => $this->ruleType->getId(),
            'pattern' => '1',
            'conditions' => [
                ['attribute_name' => 'status_id', 'operator' => '==', 'value' => '1', 'is_custom_attributes' => false],
            ],
            'actions' => [
                ['rules_workflow_actions_id' => $this->workflowAction->getId(), 'weight' => 0],
                ['rules_workflow_actions_id' => $otherWorkflowAction->getId(), 'weight' => 1],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: RuleInput!) {
                createRule(input: $input) {
                    id
                }
            }
        ', ['input' => $input]);

        $errorMessage = $response->json('errors.0.message');
        $this->assertStringContainsString('All actions must belong to the same system module. Expected module', $errorMessage);
        $this->assertStringContainsString('belongs to module', $errorMessage);
    }
}
