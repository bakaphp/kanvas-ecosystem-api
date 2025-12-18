<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDealerSocketConfiguration;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    use HasDealerSocketConfiguration;

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
}
