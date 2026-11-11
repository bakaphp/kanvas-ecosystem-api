<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\DiffOrganizationWithIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullOrganizationsFromIntrasAction;
use Kanvas\Connectors\Intras\Activities\PushApprovedOrganizationActivity;
use Kanvas\Connectors\Intras\Activities\RequestOrganizationApprovalActivity;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\OrganizationIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\UsesSipgoScratchSchema;
use Tests\Connectors\Traits\RegistersInternalIntegration;
use Tests\TestCase;

#[Group('serial')]
final class OrganizationIntrasApprovalSyncTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersInternalIntegration;
    use UsesSipgoScratchSchema;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence', 'social', 'event', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createSipgoSchema();
        $this->pointIntrasAtScratchDatabase();
    }

    protected function tearDown(): void
    {
        $this->restoreIntrasSettings();

        parent::tearDown();
    }

    public function test_to_intras_maps_columns_flags_custom_fields_and_catalogs(): void
    {
        $organization = $this->importedOrganization();
        $organization->set('es_suplidor', true);
        $organization->set('telefono', '809-555-0000');
        $organization->set('vinculacion', 'ACME Holding');

        $desired = OrganizationMapper::toIntras($organization->refresh());

        $this->assertSame([
            'name' => 'ACME SRL',
            'rnc' => '101000001',
            'is_prospect' => '1',
            'is_supplier' => '1',
            'total_employees' => '40',
        ], $desired['row']);
        $this->assertSame(['tamano' => 'Mediana', 'telefono' => '809-555-0000'], $desired['custom_fields']);
        $this->assertSame([
            'business_sectors_id' => ['table' => 'business_sectors', 'name' => 'Industria'],
            'linked_companies_id' => ['table' => 'companies', 'name' => 'ACME Holding'],
        ], $desired['catalog']);
    }

    public function test_an_organization_matching_sipgo_has_nothing_to_push(): void
    {
        $this->assertSame([], new DiffOrganizationWithIntrasAction($this->importedOrganization())->execute());
    }

    public function test_rename_and_sector_change_open_one_request_with_resolved_sector(): void
    {
        $this->seedOrganizationPolicy();
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);

        $organization = $this->importedOrganization();
        $organization->name = 'ACME, S.R.L.';
        $organization->saveOrFail();
        $organization->set('sector', 'Banca');

        $result = $this->runnableActivity(RequestOrganizationApprovalActivity::class)
            ->execute($organization->refresh(), app(Apps::class), []);

        $this->assertTrue($result['requested'], json_encode($result));

        $request = $organization->pendingApproval(OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value);
        $this->assertEquals([[
            'kind' => 'company',
            'legacy_id' => self::COMPANY_ID,
            'changes' => ['row' => [
                'name' => ['from' => 'ACME SRL', 'to' => 'ACME, S.R.L.'],
                'business_sectors_id' => ['from' => '3', 'to' => '5', 'label' => ['from' => 'Industria', 'to' => 'Banca']],
            ]],
        ]], $request->payload['items']);

        $this->assertSame('already pending', $this->runnableActivity(RequestOrganizationApprovalActivity::class)
            ->execute($organization->refresh(), app(Apps::class), [])['reason']);
    }

    public function test_push_writes_the_company_row_and_custom_field(): void
    {
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);
        $organization = $this->importedOrganization();
        $app = app(Apps::class);

        $request = ApprovalRequest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $organization->companies_id,
            'system_modules_id' => SystemModulesRepository::getByModelName(Organization::class, $app)->getId(),
            'entity_id' => $organization->getId(),
            'approval_type' => OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value,
            'origin' => ApprovalOriginEnum::SYSTEM,
            'payload' => ['organization_id' => $organization->getId(), 'items' => [[
                'kind' => 'company',
                'legacy_id' => self::COMPANY_ID,
                'changes' => [
                    'row' => ['name' => ['from' => 'ACME SRL', 'to' => 'ACME, S.R.L.']],
                    'custom_fields' => ['tamano' => ['from' => 'Mediana', 'to' => 'Grande']],
                ],
            ]]],
            'status' => ApprovalStatusEnum::APPROVED,
            'current_step' => 1,
        ]);

        $result = $this->runnableActivity(PushApprovedOrganizationActivity::class)->execute($request, $app, []);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $company = $this->sipgo()->table('companies')->where('id', self::COMPANY_ID)->first();
        $this->assertSame('ACME, S.R.L.', $company->name);
        $this->assertSame(2, (int) $company->quotes_count);
        $this->assertSame(['Grande'], $this->sipgo()->table('companies_custom_fields')->where('custom_fields_id', 90)->pluck('value')->all());
    }

    public function test_pull_finds_a_renamed_organization_by_legacy_id(): void
    {
        $organization = $this->importedOrganization();
        $organization->name = 'ACME, S.R.L.';
        $organization->saveOrFail();
        $user = static::$cachedUser;

        new PullOrganizationsFromIntrasAction(app(Apps::class), $user->getCurrentCompany(), $user)->execute();

        $this->assertSame(1, Organization::query()
            ->where('companies_id', $user->getCurrentCompany()->getId())
            ->whereIn('name', ['ACME SRL', 'ACME, S.R.L.'])
            ->count());
        $this->assertSame('ACME, S.R.L.', $organization->refresh()->name);
    }

    public function test_the_pull_fires_no_workflow_events(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;

        Action::factory()->create([
            'name' => 'Intras request organization approval',
            'model_name' => RequestOrganizationApprovalActivity::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);
        $rule = new WireRuleAction(
            $app,
            $user->getCurrentCompany()->getId(),
            Organization::class,
            WorkflowEnum::CREATE_CUSTOM_FIELD->value,
            RequestOrganizationApprovalActivity::class,
            'Intras: request Organization sync approval (test)',
            'test',
        )->execute();
        $this->assertNotNull($rule);

        $organization = $this->importedOrganization();
        $before = StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count();
        $organization->set('tamano', 'Grande');
        $this->assertSame($before + 1, StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count(), 'a Control Center edit must still reach the rule');

        new PullOrganizationsFromIntrasAction($app, $user->getCurrentCompany(), $user)->execute();

        $this->assertSame($before + 1, StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count());
        $this->assertSame('Mediana', $organization->refresh()->get('tamano'));
    }

    /**
     * An Organization as the pull leaves it for SIPGO company 701.
     */
    private function importedOrganization(): Organization
    {
        $user = static::$cachedUser;

        $organization = Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'ACME SRL',
        ]);

        $organization->set(CustomFieldEnum::INTRAS_COMPANY_ID->value, self::COMPANY_ID);
        $organization->set('rnc', '101000001');
        $organization->set('intras_is_prospect', true);
        $organization->set('total_employees', 40);
        $organization->set('sector', 'Industria');
        $organization->set('tamano', 'Mediana');

        return $organization;
    }

    private function seedOrganizationPolicy(): void
    {
        $app = app(Apps::class);

        ApprovalPolicy::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => static::$cachedUser->getCurrentCompany()->getId(),
            'system_modules_id' => SystemModulesRepository::getByModelName(Organization::class, $app)->getId(),
            'approval_type' => OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value,
        ], [
            'steps' => [['step' => 1, 'resolver' => 'company_owner', 'config' => [], 'required_approvals' => 1]],
            'handler' => null,
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'reject_policy' => 'any',
            'notify' => 'all',
            'allow_authority_override' => false,
            'expires_after_hours' => null,
        ]);
    }
}
