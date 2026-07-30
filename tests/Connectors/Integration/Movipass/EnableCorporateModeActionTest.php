<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Bouncer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Actions\ApproveCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\EnableCorporateModeAction;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Jobs\MigrateCorporateUserVariantsJob;
use Kanvas\Connectors\Movipass\Workflows\Activities\SetupApprovedCorporateCompanyActivity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Jobs\OnBoardingJob;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class EnableCorporateModeActionTest extends TestCase
{
    use HasIntegrationCompany;

    private Users $kanvasUser;
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate workflow tests are skipped in CI');
        }

        $this->kanvasUser = Auth::user();
        $this->kanvasApp = app(Apps::class);

        // These tests run without DatabaseTransactions, so the corporate flag and any
        // filed request outlive the process and would trip the guards on the next run.
        $this->kanvasUser->del('is_corporate');
        $this->discardPreviousRequests();

        // Mirror a real GraphQL request: middleware leaves the Bouncer tenant scope on the
        // user's current company, not the app-global company_0 where roles live. This is the
        // exact condition that made the admin-role lookup throw "No query results for Role".
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp, $this->kanvasUser->getCurrentCompany()));
    }

    public function testRequestProvisionsTheCompanyWithoutGrantingCorporatePrivilege(): void
    {
        Bus::fake();

        $company = $this->request();

        $this->assertEquals('Empresa de Pruebas SRL', $company->get('legal_name'));
        $this->assertEquals('Empresa Pruebas', $company->get('commercial_name'));
        $this->assertEquals('131123456', $company->get('rnc'));

        // is_corporate is the switch that grants PasoRapido corporate limits, tag access and
        // RNC on invoices. Self-reported data must not buy it without a human.
        $this->assertFalse((bool) $company->get('is_corporate'));

        $this->kanvasUser->refresh();
        $this->assertFalse((bool) $this->kanvasUser->get('is_corporate'));
        $this->assertEquals('Juan Pérez', $this->kanvasUser->get('contact_name'));

        Bus::assertNotDispatched(MigrateCorporateUserVariantsJob::class);
    }

    public function testRequestDoesNotSwitchTheUserIntoTheUnapprovedCompany(): void
    {
        Bus::fake();

        $previousCompanyId = $this->kanvasUser->getCurrentCompany()->getId();

        $company = $this->request();

        $this->kanvasUser->refresh();
        $this->assertNotEquals($previousCompanyId, $company->getId());
        $this->assertEquals($previousCompanyId, $this->kanvasUser->default_company);
    }

    public function testRequestFilesAPendingLeadForTheAdminQueue(): void
    {
        Bus::fake();

        $company = $this->request();
        $lead = $this->latestRequestLead();

        $this->assertNotNull($lead);
        $this->assertEquals(
            CorporateApplicationStatusEnum::PENDING->value,
            $lead->get(Field::STATUS->value)
        );
        $this->assertEquals(
            (string) $company->getId(),
            (string) $lead->get(Field::COMPANY_ID->value)
        );
        $this->assertEquals('131123456', $lead->get('rnc'));
    }

    public function testApprovalGrantsThePrivilege(): void
    {
        Bus::fake();

        $company = $this->request();
        $lead = $this->latestRequestLead();

        $result = new ApproveCorporateApplicationAction($lead, $this->kanvasApp, $this->kanvasUser)->execute();

        $this->assertEquals(CorporateApplicationStatusEnum::APPROVED->value, $result['status']);
        $this->assertEquals($company->getId(), $result['company_id']);
        // No invite: the applicant already has an account.
        $this->assertNull($result['invite_hash']);

        $this->assertTrue((bool) $company->fresh()->get('is_corporate'));

        $this->kanvasUser->refresh();
        $this->assertTrue((bool) $this->kanvasUser->get('is_corporate'));
        $this->assertEquals($company->getId(), $this->kanvasUser->default_company);
    }

    /**
     * Moving the vehicles is Movipass' business, not the generic approval's — it hangs off the
     * corporate-application-approved workflow event, so it is exercised through the activity.
     */
    public function testApprovedUpgradeMigratesVariantsThroughTheWorkflowActivity(): void
    {
        Bus::fake();

        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $this->kanvasUser->getCurrentCompany(),
            $this->kanvasUser
        );

        $company = $this->request();
        $lead = $this->latestRequestLead();
        new ApproveCorporateApplicationAction($lead, $this->kanvasApp, $this->kanvasUser)->execute();

        $result = new SetupApprovedCorporateCompanyActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        )->execute($lead->fresh(), $this->kanvasApp, []);

        $this->assertEquals($company->getId(), $result['company_id']);
        $this->assertTrue($result['variants_migration_dispatched']);

        Bus::assertDispatched(MigrateCorporateUserVariantsJob::class);
    }

    public function testDispatchesOnboardingForTheCorporateCompany(): void
    {
        Bus::fake();

        $company = $this->request();

        // Region + warehouse are provisioned by OnBoardingJob (Inventory Setup), the same
        // path the user-registration/lead-accept flow runs.
        Bus::assertDispatched(
            OnBoardingJob::class,
            fn (OnBoardingJob $job) => $job->branch->companies_id === $company->getId(),
        );
    }

    public function testRejectsInvalidRnc(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('RNC must be 9 or 11 digits');

        $this->request(['rnc' => '1234567']);
    }

    public function testRejectsASecondRequestWhileOneIsUnderReview(): void
    {
        Bus::fake();

        $this->request();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('You already have a corporate request under review.');

        $this->request();
    }

    public function testRejectsAlreadyCorporateUser(): void
    {
        $this->kanvasUser->set('is_corporate', true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('User is already corporate');

        $this->request();
    }

    private function request(array $overrides = []): Companies
    {
        return new EnableCorporateModeAction(
            user: $this->kanvasUser,
            app: $this->kanvasApp,
            fields: $this->validFields($overrides),
        )->execute();
    }

    private function latestRequestLead(): ?Lead
    {
        return $this->requestLeadQuery()->orderByDesc('id')->first();
    }

    private function discardPreviousRequests(): void
    {
        $this->requestLeadQuery()->get()->each(fn (Lead $lead) => $lead->softDelete());
    }

    private function requestLeadQuery()
    {
        return Lead::query()
            ->whereIn('id', function ($q) {
                $q->select('entity_id')
                    ->from(DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields')
                    ->where('model_name', Lead::class)
                    ->where('name', Field::UPGRADE_USER_ID->value)
                    ->where('value', (string) $this->kanvasUser->getId())
                    ->where('is_deleted', 0);
            })
            ->notDeleted();
    }

    private function validFields(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Empresa de Pruebas SRL',
            'commercial_name' => 'Empresa Pruebas',
            'rnc' => '131123456',
            'contact_name' => 'Juan Pérez',
            'contact_role' => 'Gerente',
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '8095551234',
        ], $overrides);
    }
}
