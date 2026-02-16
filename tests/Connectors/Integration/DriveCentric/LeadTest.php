<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PullLeadAction;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDriveCentricConfiguration;
use Tests\TestCase;

final class LeadTest extends TestCase
{
    use HasDriveCentricConfiguration;

    public function testPullLeadById(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        // First create a lead via push to get a deal ID
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushResponse = $pushLeadAction->execute();

        $this->assertNotEmpty($pushResponse);
        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        $this->assertNotNull($dealId);

        $pullLeadAction = new PullLeadAction($app, $company, $user);
        $pulledLead = $pullLeadAction->execute($dealId);

        $this->assertInstanceOf(Lead::class, $pulledLead);
        $this->assertEquals($dealId, $pulledLead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
    }

    public function testPullLeadByExistingLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        // Create and push a lead
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        $this->assertNotNull($dealId);

        // Refresh/pull the lead by existing lead object
        $pullLeadAction = new PullLeadAction($app, $company, $user);
        $refreshedLead = $pullLeadAction->execute($lead);

        $this->assertInstanceOf(Lead::class, $refreshedLead);
        $this->assertEquals($dealId, $refreshedLead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
    }

    public function testGetDealById(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        // Create and push a lead
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        $this->assertNotNull($dealId);

        // Get deal directly via service
        $leadService = new LeadService($app, $company);
        $deal = $leadService->getDealById($dealId);

        $this->assertNotEmpty($deal);
        $this->assertIsArray($deal);
    }

    public function testGetDealsByDateRange(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        // Get deals from the last 30 days
        $leadService = new LeadService($app, $company);
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');

        $deals = $leadService->getDealsByRange($startDate, $endDate, 0);

        $this->assertIsArray($deals);
        // We don't assert count since there may or may not be deals
    }

    public function testPushLeadWithVehicleOfInterest(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));

        // Add vehicle of interest
        $vehicleData = [
            'year' => 2024,
            'make' => 'Toyota',
            'model' => 'Camry',
            'trim' => 'SE',
            'stockType' => 'New',
        ];

        $response = $pushLeadAction->addVehicleOfInterest($vehicleData);
        $this->assertNotEmpty($response);
    }

    public function testPushLeadWithTradeIn(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Setup DriveCentric client
        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));

        // Add trade-in
        $tradeInData = [
            'year' => 2020,
            'make' => 'Honda',
            'model' => 'Civic',
            'vin' => '1HGBH41JXMN109186',
            'mileage' => 45000,
        ];

        $response = $pushLeadAction->addTradeIn($tradeInData);
        $this->assertNotEmpty($response);
    }
}
