<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Actions\ApproveCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Actions\RejectCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;

/**
 * The company and user an upgrade acts on come from Lead custom fields, which `updateLead` lets any
 * authenticated user write — so the guard that ties both to this app is what these cover.
 */
final class CorporateApplicationUpgradeTargetTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    private Apps $kanvasApp;
    private LeadReceiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->receiver = $this->makeReceiver();
        Notification::fake();
    }

    public function testApproveRefusesAnUpgradeWhoseCompanyIsNotInThisApp(): void
    {
        $foreign = Companies::factory()->create();
        $application = $this->makeUpgradeApplication($foreign, Auth::user());

        try {
            new ApproveCorporateApplicationAction($application, $this->kanvasApp, Auth::user())->execute();
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('does not belong to this app', $e->getMessage());
        }

        $this->assertNull($foreign->fresh()->get('is_corporate'));
        $this->assertSame(
            CorporateApplicationStatusEnum::PENDING->value,
            Field::STATUS->readFrom($application->fresh()),
        );
    }

    public function testApproveRefusesAnUpgradeWhoseUserIsNotInTheCompany(): void
    {
        $company = Companies::factory()->create();
        $this->associate(Auth::user(), $company);
        $application = $this->makeUpgradeApplication($company, Users::factory()->create());

        $this->expectException(ValidationException::class);

        new ApproveCorporateApplicationAction($application, $this->kanvasApp, Auth::user())->execute();
    }

    public function testRejectLeavesACompanyThatIsNotInThisAppAlone(): void
    {
        $foreign = Companies::factory()->create();
        $application = $this->makeUpgradeApplication($foreign, Auth::user());

        $result = new RejectCorporateApplicationAction(
            $application,
            $this->kanvasApp,
            'RNC no existe',
            Auth::user(),
        )->execute();

        $this->assertSame(CorporateApplicationStatusEnum::REJECTED->value, $result['status']);
        $this->assertFalse((bool) $foreign->fresh()->is_deleted);
    }

    public function testRejectStillReleasesAnAssociatedProvisionalCompany(): void
    {
        $company = Companies::factory()->create();
        $this->associate(Auth::user(), $company);
        $application = $this->makeUpgradeApplication($company, Auth::user());

        new RejectCorporateApplicationAction(
            $application,
            $this->kanvasApp,
            'RNC no existe',
            Auth::user(),
        )->execute();

        $this->assertTrue((bool) $company->fresh()->is_deleted);
    }

    private function makeUpgradeApplication(Companies $company, Users $upgradeUser): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), Auth::user()->getCurrentCompany()->getId())
            ->withReceiverId($this->receiver->getId())
            ->create(['title' => 'Solicitud ' . fake()->company()]);

        $lead->set('legal_name', 'Empresa de Pruebas SRL');
        $lead->set('rnc', '131123456');
        $lead->set('contact_email', fake()->unique()->safeEmail());

        Field::STATUS->writeTo($lead, CorporateApplicationStatusEnum::PENDING->value);
        Field::COMPANY_ID->writeTo($lead, (string) $company->getId());
        Field::UPGRADE_USER_ID->writeTo($lead, (string) $upgradeUser->getId());
        Field::UPGRADE_SOURCE_COMPANY_ID->writeTo($lead, (string) Auth::user()->getCurrentCompany()->getId());

        return $lead->fresh();
    }

    private function associate(Users $user, Companies $company): void
    {
        UsersAssociatedApps::create([
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'identify_id' => $user->getId(),
            'user_active' => 1,
            'user_role' => 'admin',
            'is_deleted' => 0,
        ]);
    }

    private function makeReceiver(): LeadReceiver
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        return LeadReceiver::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'users_id' => $user->getId(),
            'agents_id' => 0,
            'rotations_id' => 0,
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'name' => 'Upgrade ' . fake()->word(),
            'source_name' => 'upgrade',
            'is_default' => 0,
        ]);
    }
}
