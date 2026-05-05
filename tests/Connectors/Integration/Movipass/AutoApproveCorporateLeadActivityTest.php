<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\AutoApproveCorporateLeadActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class AutoApproveCorporateLeadActivityTest extends TestCase
{
    use HasIntegrationCompany;

    protected Apps $app;
    private LeadReceiver $corporateReceiver;
    private LeadReceiver $otherReceiver;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate workflow tests are skipped in CI');
        }

        $this->app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setIntegration(
            $this->app,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $company,
            $user
        );

        $this->corporateReceiver = $this->createReceiver('corporate');
        $this->otherReceiver = $this->createReceiver('other');

        $this->app->set(ConfigurationEnum::CORPORATE_RECEIVER_ID->value, $this->corporateReceiver->getId());
        $this->app->set(ConfigurationEnum::CORPORATE_AUTO_APPROVE->value, true);
    }

    public function testSkipsWhenLeadIsFromDifferentReceiver(): void
    {
        $lead = $this->makeCorporateLead(receiver: $this->otherReceiver);

        $result = $this->runActivity($lead);

        $this->assertEquals('skipped', $result['status']);
        $this->assertNull($lead->fresh()->get('movipass_corporate_status'));
    }

    public function testNeedsReviewWhenAutoApproveDisabled(): void
    {
        $this->app->set(ConfigurationEnum::CORPORATE_AUTO_APPROVE->value, false);

        $lead = $this->makeCorporateLead();
        Notification::fake();

        $result = $this->runActivity($lead);

        $this->assertEquals('needs_review', $result['status']);
        $this->assertEquals('needs_review', $lead->fresh()->get('movipass_corporate_status'));
        $this->assertNull($lead->fresh()->get('movipass_corporate_company_id'));
        Notification::assertSentOnDemand(Blank::class);
    }

    public function testNeedsReviewOnInvalidRncFormat(): void
    {
        // RNC must be 9 or 11 digits — 7 should fail validation.
        $lead = $this->makeCorporateLead(['rnc' => '1234567']);
        Notification::fake();

        $result = $this->runActivity($lead);

        $this->assertEquals('needs_review', $result['status']);
        $this->assertEquals('RNC must be 9 or 11 digits', $result['reason']);
        $this->assertNull($lead->fresh()->get('movipass_corporate_company_id'));
    }

    public function testApprovesValidLeadAndCreatesCompanyPlusInvite(): void
    {
        $lead = $this->makeCorporateLead();
        Notification::fake();

        $result = $this->runActivity($lead);

        $this->assertEquals('approved', $result['status']);
        $this->assertNotNull($result['company_id']);
        $this->assertNotEmpty($result['invite_hash']);

        $company = Companies::find($result['company_id']);
        $this->assertNotNull($company);
        $this->assertTrue((bool) $company->get('is_corporate'));
        // Company-level corporate fields (legal entity identity)
        $this->assertEquals($lead->get('legal_name'), $company->get('legal_name'));
        $this->assertEquals($lead->get('commercial_name'), $company->get('commercial_name'));
        $this->assertEquals($lead->get('rnc'), $company->get('rnc'));
        // User-level fields (contact_*) are NOT on the Company; they live on
        // the UsersInvite and propagate to the User via
        // PropagateCorporateFieldsToUserActivity on invite acceptance.
        $this->assertNull($company->get('contact_email'));
        $this->assertNull($company->get('contact_phone'));

        $invite = UsersInvite::where('invite_hash', $result['invite_hash'])->first();
        $this->assertNotNull($invite);
        $this->assertEquals($company->getId(), $invite->companies_id);
        $this->assertEquals($lead->get('contact_email'), $invite->email);
        // User-level corporate fields are stamped on the invite
        $this->assertTrue((bool) $invite->get('is_corporate'));
        $this->assertEquals($lead->get('contact_name'), $invite->get('contact_name'));
        $this->assertEquals($lead->get('contact_role'), $invite->get('contact_role'));
        $this->assertEquals($lead->get('contact_email'), $invite->get('contact_email'));
        $this->assertEquals($lead->get('contact_phone'), $invite->get('contact_phone'));

        $fresh = $lead->fresh();
        $this->assertEquals('approved', $fresh->get('movipass_corporate_status'));
        $this->assertEquals((string) $company->getId(), (string) $fresh->get('movipass_corporate_company_id'));
        $this->assertEquals($invite->invite_hash, $fresh->get('movipass_corporate_invite_hash'));

        Notification::assertSentOnDemand(Blank::class);
    }

    public function testRetryReusesExistingCompanyAndInvite(): void
    {
        $lead = $this->makeCorporateLead();
        Notification::fake();

        $first = $this->runActivity($lead);
        $second = $this->runActivity($lead->fresh());

        $this->assertEquals($first['company_id'], $second['company_id']);
        $this->assertEquals($first['invite_hash'], $second['invite_hash']);

        $invitesForLead = UsersInvite::where('invite_hash', $first['invite_hash'])->count();
        $this->assertEquals(1, $invitesForLead, 'Retry must not duplicate the UsersInvite record');
    }

    private function makeCorporateLead(array $overrides = [], ?LeadReceiver $receiver = null): Lead
    {
        $receiver ??= $this->corporateReceiver;
        $email = $overrides['email'] ?? fake()->unique()->safeEmail();

        $lead = Lead::factory()->withReceiverId($receiver->getId())->create([
            'apps_id' => $this->app->getId(),
            'email' => $email,
            'firstname' => $overrides['firstname'] ?? fake()->firstName(),
            'lastname' => $overrides['lastname'] ?? fake()->lastName(),
        ]);

        $lead->set('legal_name', $overrides['legal_name'] ?? 'Empresa de Pruebas SRL');
        $lead->set('commercial_name', $overrides['commercial_name'] ?? 'Empresa Pruebas');
        $lead->set('rnc', $overrides['rnc'] ?? '131123456');
        $lead->set('contact_name', $overrides['contact_name'] ?? 'Juan Pérez');
        $lead->set('contact_role', $overrides['contact_role'] ?? 'Gerente');
        $lead->set('contact_email', $overrides['contact_email'] ?? $email);
        $lead->set('contact_phone', $overrides['contact_phone'] ?? '8095551234');

        return $lead->fresh();
    }

    private function createReceiver(string $name): LeadReceiver
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $branch = $company->branch()->firstOrFail();

        return LeadReceiver::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $branch->getId(),
            'users_id' => $user->getId(),
            'agents_id' => 0,
            'rotations_id' => 0,
            'leads_sources_id' => 0,
            'leads_types_id' => 0,
            'name' => 'Test ' . $name . ' receiver',
            'source_name' => 'test-' . $name,
            'is_default' => 0,
            'total_leads' => 0,
        ]);
    }

    private function runActivity(Lead $lead): array
    {
        $activity = new AutoApproveCorporateLeadActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        return $activity->execute($lead, $this->app, []);
    }
}
