<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WordPress\Activities\PushMessageToWordPressActivity;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyWorkflowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListWorkflowOptionsTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use NeuronAI\Tools\ToolPropertyInterface;
use ReflectionMethod;
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

    /**
     * The catalogued WordPress publisher — the one step that declares both params and a required one.
     */
    private function wordPressAction(): Action
    {
        $this->artisan('kanvas:workflow-sync-actions')->assertSuccessful();

        $action = Action::query()
            ->where('model_name', PushMessageToWordPressActivity::class)
            ->first();

        $this->assertNotNull($action, 'The WordPress publisher is not in the actions catalog.');

        return $action;
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

    /**
     * A rule with no conditions leaves the pattern as the bare literal `1`, which evaluates truthy by
     * accident rather than by statement — and says nothing about what it matches.
     */
    public function testAWorkflowCreatedWithoutConditionsStillGetsOne(): void
    {
        $name = 'No conditions ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $this->workflowAction()->name,
        );

        $this->assertTrue($result['created'], $result['message'] ?? '');
        $this->assertSame(['id > 0'], $result['conditions']);

        /** @var Rule $rule */
        $rule = Rule::query()->where('id', $result['workflow_id'])->first();

        $this->assertSame(1, $rule->getRulesConditions()->where('is_deleted', 0)->count());
        $this->assertSame('1', $rule->pattern);
    }

    public function testParamsArePersistedOntoTheRuleSoActivitiesReceiveThem(): void
    {
        $action = $this->wordPressAction();
        $name = 'Publish articles ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $action->name,
            params: '{"message_type_id": 42, "status": "pending", "categories": ["News"]}',
        );

        $this->assertTrue($result['created'], $result['message'] ?? '');

        /** @var Rule $rule */
        $rule = Rule::query()->where('id', $result['workflow_id'])->first();

        $this->assertNotNull($rule);
        $this->assertSame(42, $rule->params['message_type_id']);
        $this->assertSame('pending', $rule->params['status']);
        $this->assertSame(['News'], $rule->params['categories']);
    }

    public function testRefusesWhenARequiredParamIsMissing(): void
    {
        $action = $this->wordPressAction();
        $name = 'Missing message type ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $action->name,
            params: '{"status": "pending"}',
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('message_type_id', $result['message']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testRefusesAParamNoChosenActionReads(): void
    {
        $action = $this->wordPressAction();
        $name = 'Typo param ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $action->name,
            params: '{"message_type_id": 42, "post_status": "pending"}',
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('post_status', $result['message']);
        $this->assertArrayHasKey('accepted_params', $result);
        $this->assertArrayHasKey('status', $result['accepted_params']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testRejectsParamsThatAreNotAJsonObject(): void
    {
        $name = 'Bad params ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $this->workflowAction()->name,
            params: 'message_type_id = 42',
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('JSON object', $result['message']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    public function testAnUndocumentedActionStillAcceptsParams(): void
    {
        // Only steps that documented their params can be checked; rejecting settings for a step that
        // never described any would make the guard a blocker on ~294 undocumented activities.
        $action = $this->workflowAction();
        $action->update(['params' => null, 'required_params' => null]);

        $name = 'Undocumented params ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: $this->systemModule()->model_name,
            trigger: $this->ruleType()->name,
            actions: $action->name,
            params: '{"anything": "goes"}',
        );

        $this->assertTrue($result['created'], $result['message'] ?? '');
    }

    /**
     * A trigger fires on one kind of record, and rules are matched against that record's class — so a
     * mismatched pair saves cleanly and is never considered. `after-adding-message-to-channel` fires
     * on the Channel, and the word "message" in its name makes Message the obvious wrong answer.
     */
    public function testRefusesAnEntityThatDisagreesWithWhatTheTriggerFiresOn(): void
    {
        $channelTrigger = RuleType::query()
            ->where('name', 'after-adding-message-to-channel')
            ->where('is_deleted', 0)
            ->first();

        if ($channelTrigger === null) {
            $this->markTestSkipped('This app has no channel trigger.');
        }

        $existing = Rule::query()
            ->where('rules_types_id', $channelTrigger->getId())
            ->where('is_deleted', 0)
            ->count();

        if ($existing < 2) {
            $this->markTestSkipped('Needs at least two existing rules on this trigger to form a convention.');
        }

        $name = 'Mismatched ' . fake()->unique()->uuid();

        $result = $this->tool(auth()->user())->__invoke(
            name: $name,
            entity: Message::class,
            trigger: $channelTrigger->name,
            actions: $this->workflowAction()->name,
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('never run', $result['message']);
        $this->assertSame(0, Rule::query()->where('name', $name)->count());
    }

    /**
     * `system_modules` is per-app, so every app holds its own row for the same class. Comparing module
     * ids reports a mismatch between an entity and ITSELF as soon as the evidence comes from another
     * app — which is most of the time. The comparison has to be by class.
     */
    public function testTheCorrectEntityIsNotRefusedBecauseTheEvidenceLivesInAnotherApp(): void
    {
        $channelTrigger = RuleType::query()
            ->where('name', 'after-adding-message-to-channel')
            ->where('is_deleted', 0)
            ->first();

        $module = SystemModules::query()
            ->fromApp($this->app())
            ->where('model_name', Channel::class)
            ->first();

        if ($channelTrigger === null || $module === null) {
            $this->markTestSkipped('This app has no channel trigger or Channel module.');
        }

        $tool = $this->tool(auth()->user());
        $check = new ReflectionMethod($tool, 'checkTriggerEntityFit');

        $this->assertNull(
            $check->invoke($tool, $channelTrigger, $module),
            'The entity the trigger actually fires on must never be refused.'
        );
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
