<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Activities\PushApprovedOrganizationActivity;
use Kanvas\Connectors\Intras\Activities\PushApprovedPeopleActivity;
use Kanvas\Connectors\Intras\Activities\RequestOrganizationApprovalActivity;
use Kanvas\Connectors\Intras\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Intras\Enums\OrganizationIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Traits\RegistersInternalIntegration;
use Tests\TestCase;

#[Group('serial')]
final class SetupSyncRulesCommandTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersInternalIntegration;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'workflow'];

    public function test_wires_ten_rules_and_two_policies_per_company_idempotently(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);

        foreach ([
            RequestPeopleApprovalActivity::class,
            RequestOrganizationApprovalActivity::class,
            PushApprovedPeopleActivity::class,
            PushApprovedOrganizationActivity::class,
        ] as $activity) {
            Action::factory()->create([
                'name' => class_basename($activity),
                'model_name' => $activity,
                'kind' => ActionKindEnum::WORKFLOW->value,
            ]);
        }

        $this->artisan('kanvas:intras-setup-sync-rules', ['app_id' => $app->getId()])->assertSuccessful();
        $this->artisan('kanvas:intras-setup-sync-rules', ['app_id' => $app->getId()])->assertSuccessful();

        $rules = Rule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'like', 'Intras: %')
            ->where('is_deleted', 0)
            ->get();

        $this->assertCount(10, $rules);

        $peopleModule = SystemModulesRepository::getByModelName(People::class, $app)->getId();
        $organizationModule = SystemModulesRepository::getByModelName(Organization::class, $app)->getId();
        $requestModule = SystemModulesRepository::getByModelName(ApprovalRequest::class, $app)->getId();

        $this->assertSame(4, $rules->where('systems_modules_id', $peopleModule)->count());
        $this->assertSame(4, $rules->where('systems_modules_id', $organizationModule)->count());
        $this->assertSame(2, $rules->where('systems_modules_id', $requestModule)->count());
        $this->assertEqualsCanonicalizing(
            [WorkflowEnum::UPDATED->value, WorkflowEnum::CREATE_CUSTOM_FIELD->value, WorkflowEnum::CREATE_CUSTOM_FIELDS->value, WorkflowEnum::AGENT_UPDATED->value],
            $rules->where('systems_modules_id', $peopleModule)->map(fn (Rule $rule) => $rule->type->name)->all(),
        );

        $conditions = RuleCondition::query()->whereIn('rules_id', $rules->pluck('id'))->get();
        $this->assertCount(2, $conditions);
        $this->assertEqualsCanonicalizing(
            [PeopleIntrasSyncApprovalTypeEnum::UPDATE->value, OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value],
            $conditions->pluck('value')->all(),
        );
        $this->assertSame(['approval_type'], $conditions->pluck('attribute_name')->unique()->values()->all());

        foreach ([
            [$peopleModule, PeopleIntrasSyncApprovalTypeEnum::UPDATE->value],
            [$organizationModule, OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value],
        ] as [$module, $type]) {
            $this->assertSame(1, ApprovalPolicy::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('system_modules_id', $module)
                ->where('approval_type', $type)
                ->count(), $type);
        }
    }

    public function test_refuses_an_app_with_no_intras_companies(): void
    {
        $app = app(Apps::class);
        IntegrationsCompany::query()
            ->where('integrations_id', Integrations::getByName(IntegrationsEnum::INTRAS->value)->getId())
            ->where('companies_id', static::$cachedUser->getCurrentCompany()->getId())
            ->delete();

        $this->artisan('kanvas:intras-setup-sync-rules', ['app_id' => $app->getId()])->assertFailed();
    }
}
