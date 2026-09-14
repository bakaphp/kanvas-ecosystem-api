<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WordPress\Activities\PushMessageToWordPressActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyWorkflowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListCompanyWorkflowsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\UpdateCompanyWorkflowTool;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;
use Throwable;

final class UpdateCompanyWorkflowToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'workflow', 'crm'];

    public function testAddsAConditionToAnExistingWorkflow(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'message_type_id == 2782 | is_publish == 1',
        );

        $this->assertTrue($result['updated'], $result['message'] ?? '');
        $this->assertSame(
            ['message_type_id == 2782', 'is_publish == 1'],
            $result['conditions']
        );

        $rule->refresh();
        $this->assertSame('1 AND 2', $rule->pattern, 'The pattern must widen with the condition count.');
        $this->assertSame(2, $rule->getRulesConditions()->where('is_deleted', 0)->count());
    }

    public function testConditionsReplaceRatherThanAccumulate(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782 | is_publish == 1');

        $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'is_publish == 1',
        );

        $rule->refresh();

        $this->assertSame(1, $rule->getRulesConditions()->where('is_deleted', 0)->count());
        $this->assertSame('1', $rule->pattern);
    }

    /**
     * "Clear them all" still leaves one: "runs on everything" is a statement a rule should make, not
     * an absence a reader has to infer.
     */
    public function testClearingConditionsLeavesTheDefaultRatherThanNone(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'none',
        );

        $this->assertTrue($result['updated']);
        $this->assertSame(['id > 0'], $result['conditions']);
        $this->assertSame(1, $rule->refresh()->getRulesConditions()->where('is_deleted', 0)->count());
    }

    public function testDeactivatingKeepsTheWorkflowRatherThanDestroyingIt(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(auth()->user())->__invoke(workflow_id: $rule->getId(), is_active: false);

        $this->assertTrue($result['updated']);
        $this->assertFalse($result['is_active']);
        $this->assertNotNull(Rule::query()->where('id', $rule->getId())->first(), 'It must still exist.');
    }

    /**
     * params REPLACES, so a caller that forgets a required one would otherwise turn a working rule
     * into one that fires and does the wrong thing.
     */
    public function testRefusesParamsThatDropARequiredOneFromTheCurrentActions(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782', $this->wordPressAction());

        $result = $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            params: '{"status": "pending"}',
        );

        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('message_type_id', $result['message']);
    }

    /**
     * `is_publish` on a table whose column is `is_public` saves cleanly and matches nothing forever.
     * A warning, not a refusal — conditions can legitimately name a runtime param that is no column.
     */
    public function testWarnsWhenAConditionNamesAFieldThatDoesNotExist(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'no_such_column_here == 1',
        );

        $this->assertTrue($result['updated'], 'It must still save — this is a warning, not a block.');
        $this->assertArrayHasKey('warnings', $result);
        $this->assertStringContainsString('no_such_column_here', $result['warnings'][0]);
    }

    public function testARealFieldProducesNoWarning(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'is_deleted == 0',
        );

        $this->assertTrue($result['updated']);
        $this->assertArrayNotHasKey('warnings', $result);
    }

    public function testAWorkflowFromAnotherCompanyIsNotFound(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');
        $rule->companies_id = 999999;
        $rule->saveQuietly();

        $result = $this->tool(auth()->user())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'is_publish == 1',
        );

        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('does not belong to this company', $result['message']);
    }

    public function testANonAdminCannotChangeAutomation(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(Users::factory()->create())->__invoke(
            workflow_id: $rule->getId(),
            conditions: 'is_publish == 1',
        );

        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('administrator', $result['message']);
    }

    public function testAnEmptyCallSaysSoRatherThanReportingSuccess(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->tool(auth()->user())->__invoke(workflow_id: $rule->getId());

        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('Nothing to change', $result['message']);
    }

    /**
     * A rule bound to a legacy entity is never matched, so it looks healthy and silently does nothing.
     * The lister has to say so, because no error ever will.
     */
    public function testListingFlagsAWorkflowThatCanNeverRun(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $legacy = SystemModules::query()
            ->fromApp($this->app())
            ->where('model_name', 'not like', 'Kanvas%')
            ->first();

        if ($legacy === null) {
            $this->markTestSkipped('This app has no legacy system module to bind to.');
        }

        $rule->systems_modules_id = $legacy->getId();
        $rule->saveQuietly();

        $result = $this->lister()->__invoke(search: $rule->name);

        $entry = null;
        foreach ($result['workflows'] as $workflow) {
            if ($workflow['workflow_id'] === $rule->getId()) {
                $entry = $workflow;
            }
        }

        $this->assertNotNull($entry);
        $this->assertFalse($entry['will_run'], 'A legacy-bound workflow must be flagged as unable to run.');
        $this->assertArrayHasKey('warning', $result);
    }

    public function testListingShowsWhatAWorkflowWatchesAndRequires(): void
    {
        $rule = $this->workflowWith('message_type_id == 2782');

        $result = $this->lister()->__invoke(search: $rule->name);

        $entry = null;
        foreach ($result['workflows'] as $workflow) {
            if ($workflow['workflow_id'] === $rule->getId()) {
                $entry = $workflow;
            }
        }

        $this->assertNotNull($entry);
        $this->assertSame(['message_type_id == 2782'], $entry['conditions']);
        $this->assertNotEmpty($entry['actions']);
        $this->assertTrue($entry['will_run']);
    }

    private function workflowWith(string $conditions, ?Action $action = null): Rule
    {
        $action ??= $this->anyAction();
        $name = 'Smoke workflow ' . fake()->unique()->uuid();

        $result = new CreateCompanyWorkflowTool()
            ->withContext($this->app(), $this->company(), auth()->user())
            ->forRequestingUser(auth()->user())
            ->__invoke(
                name: $name,
                entity: $this->systemModule()->model_name,
                trigger: $this->ruleType()->name,
                actions: $action->name,
                params: $action->requiredParamNames() !== []
                    ? json_encode(['message_type_id' => 2782, 'status' => 'pending'])
                    : null,
                conditions: $conditions,
            );

        $this->assertTrue($result['created'] ?? false, $result['message'] ?? 'workflow was not created');

        return Rule::query()->where('id', $result['workflow_id'])->firstOrFail();
    }

    private function wordPressAction(): Action
    {
        $this->artisan('kanvas:workflow-sync-actions')->assertSuccessful();

        return Action::query()
            ->where('model_name', PushMessageToWordPressActivity::class)
            ->firstOrFail();
    }

    /**
     * A named, always-present step rather than "whatever the catalog happens to hold".
     *
     * Searching for any row with no required params depended on what the environment had been synced
     * with — it found a legacy row on a developer's tenant and nothing at all on CI's freshly synced
     * one, so eleven tests failed for a reason unrelated to what they assert. The factory is keyed on
     * `model_name`, so it returns the catalogued Generate Company Dashboard row or creates it.
     */
    private function anyAction(): Action
    {
        return Action::factory()->create();
    }

    private function tool(Users $requestingUser): UpdateCompanyWorkflowTool
    {
        return new UpdateCompanyWorkflowTool()
            ->withContext($this->app(), $this->company(), auth()->user())
            ->forRequestingUser($requestingUser);
    }

    private function lister(): ListCompanyWorkflowsTool
    {
        return new ListCompanyWorkflowsTool()
            ->withContext($this->app(), $this->company(), auth()->user());
    }

    private function systemModule(): SystemModules
    {
        return SystemModulesRepository::getByModelName(Lead::class, $this->app());
    }

    private function ruleType(): RuleType
    {
        try {
            return RuleType::getByName(WorkflowEnum::CREATED->value);
        } catch (Throwable) {
            return RuleType::factory()->create();
        }
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }
}
