<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Actions\PullLeadAction;
use Kanvas\Connectors\DealerSocket\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Tests\Connectors\Traits\HasDealerSocketConfiguration;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    use HasDealerSocketConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('DealerSocket integration tests are skipped in CI');
        }
    }

    public function testCreateLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        $response = $leadService->saveLead($lead);

        $this->assertArrayHasKey('leadId', $response);
    }

    public function testUpdateLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        $response = $leadService->saveLead($lead);

        $lead->title = 'TEST - ' . now()->format('H:i:s');
        $lead->description = 'Probando actualización';
        $lead->save();
        $response = $leadService->updateLead($lead);

        $this->assertNotEmpty($response['success']);
    }

    public function testGetLeadById(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        $response = $leadService->saveLead($lead);

        $customerId = $lead->people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);
        $response = $leadService->getLeadByCustomerId($customerId);

        $this->assertArrayHasKey('customer', $response);
        $this->assertArrayHasKey('events', $response);
        $this->assertNotEmpty($response['events']);
        $this->assertEquals($response['customer']['firstName'], $people->firstname);
    }

    public function testConvertLeadToDto(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        $response = $leadService->saveLead($lead);

        $customerId = $lead->people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);
        $response = $leadService->getLeadByCustomerId($customerId);

        $response['company'] = $company;
        $lead = DataTransferObjectLead::from(
            $user,
            $app,
            $response
        );

        $this->assertEquals($lead->title, $people->firstname . ' ' . $people->lastname . ' Opp');
        $this->assertEquals($lead->people->firstname, $people->firstname);
        $this->assertEquals($lead->people->lastname, $people->lastname);
        $this->assertEquals($lead->people->firstname, $response['customer']['firstName']);
    }

    public function testPullLeadAction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        $response = $leadService->saveLead($lead);

        $customerId = $lead->people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);

        $pullLeadAction = new PullLeadAction(
            $app,
            $company,
            $user
        );

        $leadsData = $pullLeadAction->execute(
            null,
            $customerId,
            true
        );

        $this->assertNotEmpty($leadsData);
        $this->assertEquals($leadsData[0]['people_id'], $people->getId());
    }

    public function testAddNoteToLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        $response = $leadService->saveLead($lead);

        $note = new DealerSocketWorkNoteService(
            $app,
            $company,
        )->addSimpleNote($lead, 'This is a test note');

        $this->assertArrayHasKey('success', $note);
    }

    public function testCreateServiceLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        // Create a service lead type
        $serviceLeadType = LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Service',
            ],
            [
                'description' => 'Service Lead Type',
                'is_active' => 1,
            ]
        );

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create([
                'leads_types_id' => $serviceLeadType->getId(),
            ]);

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        // Verify this is detected as a service lead
        $this->assertTrue($lead->isServiceLead());

        $response = $leadService->saveLead($lead);

        $this->assertArrayHasKey('leadId', $response);
        $this->assertArrayHasKey('customerId', $response);
        $this->assertTrue($response['success']);
    }

    /**
     * Test that leads with an owner are automatically marked as Store Visit (227)
     */
    public function testLeadWithOwnerMarkedAsStoreVisit(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        // Create lead with an owner assigned
        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create([
                'leads_owner_id' => $user->getId(), // Assign owner to trigger Store Visit
            ]);

        $this->setupDealerSocketConfiguration($company, $app);

        $leadService = new DealerSocketLeadService($app, $company);

        // Verify lead has an owner
        $this->assertNotNull($lead->owner);

        $response = $leadService->saveLead($lead);

        $this->assertArrayHasKey('leadId', $response);
        $this->assertTrue($response['success']);

        // Search for the lead to verify status was updated to Store Visit (227)
        $customerId = $lead->people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);
        $searchResult = $leadService->getLeadByCustomerId($customerId);

        $this->assertNotEmpty($searchResult['events']);

        // Find the event we just created
        $leadId = $lead->get(CustomFieldEnum::DEALER_SOCKET_LEAD_ID->value);
        $matchingEvent = collect($searchResult['events'])->first(function ($event) use ($leadId) {
            return $event['eventId'] == $leadId;
        });

        $this->assertNotNull($matchingEvent, 'Created lead not found in search results');
        $this->assertEquals(227, $matchingEvent['status'], 'Lead status should be Store Visit (227)');
    }
}
