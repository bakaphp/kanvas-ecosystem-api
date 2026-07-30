<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use App\GraphQL\Ecosystem\Mutations\Companies\CorporateApplicationMutation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Actions\ApproveCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Actions\RejectCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\UsersInvite;
use Tests\TestCase;

final class CorporateApplicationApprovalTest extends TestCase
{
    private Apps $kanvasApp;
    private LeadReceiver $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate workflow tests are skipped in CI');
        }

        $this->kanvasApp = app(Apps::class);
        $this->receiver = $this->createReceiver();
    }

    public function testApproveCreatesCorporateCompanyAndInvite(): void
    {
        $lead = $this->makePendingApplication();
        Notification::fake();

        $result = new ApproveCorporateApplicationAction($lead, $this->kanvasApp, Auth::user())->execute();

        $this->assertEquals(CorporateApplicationStatusEnum::APPROVED->value, $result['status']);

        $company = Companies::find($result['company_id']);
        $this->assertNotNull($company);
        $this->assertTrue((bool) $company->get('is_corporate'));
        $this->assertEquals($lead->get('rnc'), $company->get('rnc'));

        $invite = UsersInvite::where('invite_hash', $result['invite_hash'])->first();
        $this->assertNotNull($invite);
        $this->assertEquals($company->getId(), $invite->companies_id);
        $this->assertTrue((bool) $invite->get('is_corporate'));

        $fresh = $lead->fresh();
        $this->assertEquals(
            CorporateApplicationStatusEnum::APPROVED->value,
            $fresh->get(Field::STATUS->value)
        );
        $this->assertEquals(
            (string) Auth::user()->getId(),
            (string) $fresh->get(Field::REVIEWED_BY->value)
        );
        $this->assertNotEmpty($fresh->get(Field::REVIEWED_AT->value));

        Notification::assertSentOnDemand(Blank::class);
    }

    public function testApproveIsIdempotent(): void
    {
        $lead = $this->makePendingApplication();
        Notification::fake();

        $first = new ApproveCorporateApplicationAction($lead, $this->kanvasApp)->execute();
        $second = new ApproveCorporateApplicationAction($lead->fresh(), $this->kanvasApp)->execute();

        $this->assertEquals($first['company_id'], $second['company_id']);
        $this->assertEquals($first['invite_hash'], $second['invite_hash']);
        $this->assertEquals(1, UsersInvite::where('invite_hash', $first['invite_hash'])->count());
    }

    public function testRejectRecordsReasonAndCreatesNothing(): void
    {
        $lead = $this->makePendingApplication();
        Notification::fake();

        $result = new RejectCorporateApplicationAction($lead, $this->kanvasApp, 'RNC no existe en DGII', Auth::user())->execute();

        $this->assertEquals(CorporateApplicationStatusEnum::REJECTED->value, $result['status']);
        $this->assertEquals('RNC no existe en DGII', $result['reason']);
        $this->assertFalse($result['applicant_notified']);

        $fresh = $lead->fresh();
        $this->assertEquals(
            CorporateApplicationStatusEnum::REJECTED->value,
            $fresh->get(Field::STATUS->value)
        );
        $this->assertEquals('RNC no existe en DGII', $fresh->get(Field::STATUS_REASON->value));
        $this->assertNull($fresh->get(Field::COMPANY_ID->value));

        Notification::assertNothingSent();
    }

    public function testRejectEmailsApplicantOnlyWhenTemplateIsConfigured(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::CORPORATE_REJECTED_TEMPLATE->value, 'corporate-rejected');

        try {
            $lead = $this->makePendingApplication();
            Notification::fake();

            $result = new RejectCorporateApplicationAction($lead, $this->kanvasApp, 'Datos de contacto no verificables')->execute();

            $this->assertTrue($result['applicant_notified']);
            Notification::assertSentOnDemand(Blank::class);
        } finally {
            $this->kanvasApp->del(ConfigurationEnum::CORPORATE_REJECTED_TEMPLATE->value);
        }
    }

    public function testApproveMutationDecidesTheApplication(): void
    {
        $lead = $this->makePendingApplication();
        Notification::fake();

        $this->graphQL('
            mutation($id: ID!) {
                approveCorporateApplication(id: $id) {
                    status
                    company_id
                    invite_hash
                }
            }
        ', ['id' => $lead->getId()])
            ->assertSuccessful()
            ->assertJson(['data' => ['approveCorporateApplication' => ['status' => 'approved']]]);

        $this->assertEquals(
            CorporateApplicationStatusEnum::APPROVED->value,
            $lead->fresh()->get(Field::STATUS->value)
        );
    }

    public function testRejectRequiresANonEmptyReason(): void
    {
        $lead = $this->makePendingApplication();

        // Over HTTP, TrimStrings + ConvertEmptyStringsToNull make the schema's String!
        // reject a blank reason first; this covers the resolver guard for internal callers.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A rejection reason is required.');

        new CorporateApplicationMutation()->reject(null, [
            'id' => $lead->getId(),
            'reason' => '   ',
        ]);
    }

    public function testAlreadyRejectedApplicationCannotBeApproved(): void
    {
        $lead = $this->makePendingApplication();
        Notification::fake();

        new RejectCorporateApplicationAction($lead, $this->kanvasApp, 'RNC falso')->execute();

        $this->graphQL('
            mutation($id: ID!) {
                approveCorporateApplication(id: $id) { status }
            }
        ', ['id' => $lead->fresh()->getId()])
            ->assertGraphQLErrorMessage('This application was already rejected.');
    }

    public function testNonApplicationLeadIsRejectedByTheMutation(): void
    {
        $lead = $this->makePendingApplication();
        $lead->del(Field::STATUS->value);

        $this->graphQL('
            mutation($id: ID!) {
                approveCorporateApplication(id: $id) { status }
            }
        ', ['id' => $lead->getId()])
            ->assertGraphQLErrorMessage('This lead is not a corporate application.');
    }

    /**
     * The admin queue has no dedicated endpoint on purpose — this is the query the panel
     * runs, so it is covered here rather than left as an assumption.
     */
    public function testLeadsQueryFiltersTheReviewQueue(): void
    {
        $pending = $this->makePendingApplication();
        Notification::fake();

        $decided = $this->makePendingApplication();
        new RejectCorporateApplicationAction($decided, $this->kanvasApp, 'RNC falso')->execute();

        $response = $this->graphQL('
            query {
                leads(
                    first: 50
                    hasCustomFields: {
                        AND: [
                            { column: NAME, value: "corporate_application_status" }
                            { column: VALUE, operator: IN, value: ["pending", "needs_review"] }
                        ]
                    }
                ) {
                    data { id }
                }
            }
        ')->assertSuccessful();

        $ids = array_column($response->json('data.leads.data'), 'id');

        $this->assertContains((string) $pending->getId(), $ids);
        $this->assertNotContains((string) $decided->getId(), $ids);
    }

    private function makePendingApplication(array $overrides = []): Lead
    {
        $email = $overrides['email'] ?? fake()->unique()->safeEmail();
        $company = Auth::user()->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $company->getId())
            ->withReceiverId($this->receiver->getId())
            ->create([
                'email' => $email,
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ]);

        $lead->set('legal_name', $overrides['legal_name'] ?? 'Empresa de Pruebas SRL');
        $lead->set('commercial_name', 'Empresa Pruebas');
        $lead->set('rnc', $overrides['rnc'] ?? '131123456');
        $lead->set('contact_name', 'Juan Pérez');
        $lead->set('contact_role', 'Gerente');
        $lead->set('contact_email', $email);
        $lead->set('contact_phone', '8095551234');
        $lead->set(Field::STATUS->value, CorporateApplicationStatusEnum::PENDING->value);

        return $lead->fresh();
    }

    private function createReceiver(): LeadReceiver
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $branch = $company->branch()->firstOrFail();

        return LeadReceiver::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $branch->getId(),
            'users_id' => $user->getId(),
            'agents_id' => 0,
            'rotations_id' => 0,
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'name' => 'Test corporate approval receiver',
            'source_name' => 'test-corporate-approval',
            'is_default' => 0,
            'total_leads' => 0,
        ]);
    }
}
