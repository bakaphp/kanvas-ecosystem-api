<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Tests\Stubs\Organizations\RecordingOrganizationCreatedActivity;
use Tests\TestCase;

/**
 * Regression for the pull-triggers-push loop: PullPropertyContactAction (Salesforce) creates an
 * Organization from a broker's Company_Name__c, and if a tenant has a Rule wired on Organization
 * `created` -> a push-to-CRM activity, that would push a brand-new, minimally-populated Account back
 * to Salesforce. `runWorkflow: false` on the DTO is what must stop that.
 */
class CreateOrganizationActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        RecordingOrganizationCreatedActivity::reset();
        $this->seedOrganizationCreatedRule(RecordingOrganizationCreatedActivity::class);
    }

    private function seedOrganizationCreatedRule(string $activityClass): void
    {
        $ruleType = RuleType::firstOrCreate(['name' => WorkflowEnum::CREATED->value]);
        $organizationModuleId = SystemModulesRepository::getByModelName(Organization::class, $this->kanvasApp)->getId();

        $rule = Rule::factory()->withAsync(false)->create([
            'systems_modules_id' => $organizationModuleId,
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'rules_types_id' => $ruleType->getId(),
            'name' => 'Push new organization to CRM',
            'description' => 'test',
            'params' => [],
        ]);
        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $action = WorkflowAction::factory()->create([
            'name' => class_basename($activityClass),
            'model_name' => $activityClass,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);
        $workflowAction = RuleWorkflowAction::factory()->create([
            'actions_id' => $action->getId(),
            'system_modules_id' => $organizationModuleId,
        ]);
        RuleAction::factory()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $workflowAction->getId(),
            'weight' => 0,
        ]);
    }

    public function testFiresTheCreatedWorkflowByDefault(): void
    {
        new CreateOrganizationAction(
            new OrganizationData(
                company: $this->company,
                user: static::$cachedUser,
                app: $this->kanvasApp,
                name: 'Pushable Org Inc ' . uniqid(),
            ),
        )->execute();

        $this->assertCount(1, RecordingOrganizationCreatedActivity::$calls);
    }

    public function testDoesNotFireTheCreatedWorkflowWhenRunWorkflowIsFalse(): void
    {
        new CreateOrganizationAction(
            new OrganizationData(
                company: $this->company,
                user: static::$cachedUser,
                app: $this->kanvasApp,
                name: 'Pulled From Salesforce Broker Co ' . uniqid(),
                runWorkflow: false,
            ),
        )->execute();

        $this->assertSame([], RecordingOrganizationCreatedActivity::$calls);
    }

    public function testDoesNotFireTheCreatedWorkflowOnAFindNotACreate(): void
    {
        $name = 'Already Exists Co ' . uniqid();

        new CreateOrganizationAction(
            new OrganizationData(
                company: $this->company,
                user: static::$cachedUser,
                app: $this->kanvasApp,
                name: $name,
            ),
        )->execute();
        RecordingOrganizationCreatedActivity::reset();

        new CreateOrganizationAction(
            new OrganizationData(
                company: $this->company,
                user: static::$cachedUser,
                app: $this->kanvasApp,
                name: $name,
            ),
        )->execute();

        $this->assertSame([], RecordingOrganizationCreatedActivity::$calls);
    }
}
