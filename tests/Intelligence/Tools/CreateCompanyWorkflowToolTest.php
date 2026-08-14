<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyWorkflowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListWorkflowOptionsTool;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use NeuronAI\Tools\ToolPropertyInterface;
use Tests\TestCase;
use Throwable;

class CreateCompanyWorkflowToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'workflow', 'crm', 'intelligence'];

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function ruleType(): RuleType
    {
        try {
            return RuleType::getByName(WorkflowEnum::CREATED->value);
        } catch (Throwable) {
            return RuleType::factory()->create();
        }
    }

    private function systemModule(): SystemModules
    {
        return SystemModulesRepository::getByModelName(Lead::class, $this->app());
    }

    private function workflowAction(): Action
    {
        return Action::first() ?? Action::factory()->create();
    }

    private function tool(?Users $requestingUser = null): CreateCompanyWorkflowTool
    {
        $user = auth()->user();

        return new CreateCompanyWorkflowTool()
            ->withContext($this->app(), $this->company(), $user)
            ->forRequestingUser($requestingUser);
    }

    public function testCreatesWorkflowScopedToTheCompanyNeverGlobal(): void
    {
        $ruleType = $this->ruleType();
        $module = $this->systemModule();
        $action = $this->workflowAction();
        $name = 'Notify owner ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $module->model_name,
            trigger: $ruleType->name,
            actions: $action->name,
            conditions: 'status == new',
        );

        $this->assertTrue($result['created'], $result['message'] ?? '');

        /** @var Rule $rule */
        $rule = Rule::query()->where('id', $result['workflow_id'])->firstOrFail();

        $this->assertSame($this->company()->getId(), (int) $rule->companies_id);
        $this->assertNotSame(AppEnums::GLOBAL_COMPANY_ID->getValue(), (int) $rule->companies_id);
        $this->assertSame($this->app()->getId(), (int) $rule->apps_id);
        $this->assertSame($ruleType->getId(), (int) $rule->rules_types_id);
        $this->assertSame($module->getId(), (int) $rule->systems_modules_id);
        $this->assertTrue($rule->is_async);
        $this->assertSame('1', $rule->pattern);

        $condition = $rule->getRulesConditions()->first();
        $this->assertSame('status', $condition->attribute_name);
        $this->assertSame('==', $condition->operator);
        $this->assertSame('new', $condition->value);

        $this->assertSame(1, $rule->workflowActivities()->count());
    }

    public function testWorkflowHasNoCompanyParameterSoItCannotTargetAnotherCompany(): void
    {
        // The guarantee is structural: if a company argument ever appears, an admin of company A
        // could write automation that runs for company B (or for every tenant, via companies_id 0).
        $properties = array_map(
            fn (ToolPropertyInterface $property): string => $property->getName(),
            new CreateCompanyWorkflowTool()->getProperties()
        );

        $this->assertNotContains('company', $properties);
        $this->assertNotContains('company_id', $properties);
        $this->assertNotContains('companies_id', $properties);
        $this->assertNotContains('is_global', $properties);
    }

    public function testDeniesWhenTheRequestingUserIsNotAnAdmin(): void
    {
        $ruleType = $this->ruleType();
        $name = 'Blocked workflow ' . fake()->unique()->uuid();

        $result = $this->tool(Users::factory()->create())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $ruleType->name,
            actions: $this->workflowAction()->name,
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('administrator', $result['message']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testDeniesWhenThereIsNoIdentifiedRequestingUser(): void
    {
        // Registry-resolved tools run with the AGENT's user as context — usually an admin. Falling
        // back to it would authorize anyone chatting with the agent.
        $name = 'Anonymous workflow ' . fake()->unique()->uuid();

        $result = $this->tool()->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $this->workflowAction()->name,
        );

        $this->assertFalse($result['created']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testUnknownTriggerReturnsTheValidOnesInsteadOfThrowing(): void
    {
        $this->ruleType();

        $result = $this->tool(auth()->user())->__invoke(
            name: 'Invalid trigger ' . fake()->unique()->uuid(),
            entity: $this->systemModule()->model_name,
            trigger: 'when-the-moon-is-full',
            actions: $this->workflowAction()->name,
        );

        $this->assertFalse($result['created']);
        $this->assertNotEmpty($result['available_triggers']);
    }

    public function testUnknownActionReturnsSuggestionsInsteadOfCreatingAPartialWorkflow(): void
    {
        $name = 'Invalid action ' . fake()->unique()->uuid();
        $this->workflowAction();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: 'Fly To The Moon',
        );

        $this->assertFalse($result['created']);
        $this->assertArrayHasKey('suggested_actions', $result);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testDoesNotDuplicateAnExistingWorkflow(): void
    {
        $name = 'Duplicate ' . fake()->unique()->uuid();
        $arguments = [
            'name' => $name,
            'entity' => $this->systemModule()->model_name,
            'trigger' => $this->ruleType()->name,
            'actions' => $this->workflowAction()->name,
        ];

        $first = $this->tool(auth()->user())->__invoke(...$arguments);
        $second = $this->tool(auth()->user())->__invoke(...$arguments);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertTrue($second['already_exists']);
        $this->assertSame($first['workflow_id'], $second['workflow_id']);
        $this->assertSame(1, Rule::query()->where('name', $name)->count());
    }

    public function testParsesConditionsWhoseAttributeContainsAWordOperator(): void
    {
        // "min" contains "in": a single longest-first alternation reads "min > 3" as "m in > 3".
        $result = $this->tool(auth()->user())->__invoke(
            name: 'Word operator ' . fake()->unique()->uuid(),
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $this->workflowAction()->name,
            conditions: 'min > 3 | status not in [closed, lost]',
        );

        $this->assertTrue($result['created'], $result['message'] ?? '');

        /** @var Rule $rule */
        $rule = Rule::query()->where('id', $result['workflow_id'])->firstOrFail();
        $conditions = $rule->getRulesConditions()->get();

        $this->assertSame('min', $conditions[0]->attribute_name);
        $this->assertSame('>', $conditions[0]->operator);
        $this->assertSame('3', $conditions[0]->value);

        $this->assertSame('status', $conditions[1]->attribute_name);
        $this->assertSame('not in', $conditions[1]->operator);
        $this->assertSame('1 AND 2', $rule->pattern);
    }

    public function testUnreadableConditionIsRejectedWithFormatGuidance(): void
    {
        $name = 'Bad condition ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $this->workflowAction()->name,
            conditions: 'whenever the lead looks promising',
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('attribute operator value', $result['message']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testListWorkflowOptionsReturnsTheCatalogsTheCreateToolExpects(): void
    {
        $ruleType = $this->ruleType();
        $this->workflowAction();

        $result = new ListWorkflowOptionsTool()
            ->withContext($this->app(), $this->company(), auth()->user())
            ->__invoke();

        $this->assertSame('success', $result['status']);
        $this->assertContains($ruleType->name, $result['triggers']);
        $this->assertNotEmpty($result['actions']);
        $this->assertArrayHasKey('entities', $result);
    }
}
