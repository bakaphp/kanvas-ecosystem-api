<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleAction;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleLeadAction;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Actions\PushPeopleAction;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDriveCentricConfiguration;
use Tests\TestCase;

final class PeopleTest extends TestCase
{
    use HasDriveCentricConfiguration;

    /**
     * Test that pushing a person without a DriveCentric ID throws an exception.
     * Customers must be created through leads first.
     */
    public function testPushPeopleWithoutLeadThrowsException(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $pushPeopleAction = new PushPeopleAction($people);

        $this->expectException(DriveCentricException::class);
        $this->expectExceptionMessage('Customer does not exist in DriveCentric');

        $pushPeopleAction->execute();
    }

    /**
     * Test the full flow: Create lead -> customer is created -> update customer.
     */
    public function testCreateLeadThenUpdateCustomer(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a person
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        // Create a lead for this person (this will create the customer in DriveCentric)
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push the lead to DriveCentric (creates customer)
        $pushLeadAction = new PushLeadAction($lead);
        $leadResponse = $pushLeadAction->execute();

        $this->assertNotEmpty($leadResponse);

        // Refresh the people record to get the customer ID
        $lead->refresh();
        $people = $lead->people;
        $customerId = $people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);

        $this->assertNotNull($customerId, 'Customer ID should be set after lead creation');
        // Now update the customer data
        $people->firstname = 'UpdatedFirstName';
        $people->save();

        $pushPeopleAction = new PushPeopleAction($people);
        $response = $pushPeopleAction->execute();

        $this->assertNotEmpty($response);
    }

    /**
     * Test updating an existing customer (already has DriveCentric ID).
     */
    public function testUpdateExistingCustomer(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create person and lead
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to create customer
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $people->refresh();
        $customerId = $people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);
        $this->assertNotNull($customerId);

        // Update customer
        $people->lastname = 'UpdatedLastName';
        $people->save();

        $pushPeopleAction = new PushPeopleAction($people->fresh());
        $this->assertTrue($pushPeopleAction->isSynced());

        $response = $pushPeopleAction->execute();
        $this->assertNotEmpty($response);

        // Customer ID should remain the same
        $this->assertEquals($customerId, $pushPeopleAction->getCustomerId());
    }

    /**
     * Test helper methods on PushPeopleAction.
     */
    public function testPushPeopleActionHelperMethods(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $pushPeopleAction = new PushPeopleAction($people);

        // Before sync (no lead created yet)
        $this->assertFalse($pushPeopleAction->isSynced());
        $this->assertNull($pushPeopleAction->getCustomerId());
        $this->assertNull($pushPeopleAction->getDealId());
    }

    /**
     * Test pulling people by email.
     */
    public function testPullPeopleByEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a person and lead first
        $email = 'test+' . fake()->unique()->userName . '@kanvas.dev';

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        $people->addEmail($email);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to create customer in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        sleep(5);
        // Now pull the customer by email
        $pullPeopleAction = new PullPeopleAction($app, $company, $user);
        $pulledPeople = $pullPeopleAction->execute(email: $email);

        $this->assertNotEmpty($pulledPeople);
        $this->assertInstanceOf(People::class, $pulledPeople);
        $this->assertNotNull($pulledPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
    }

    /**
     * Test pulling people by phone.
     */
    public function testPullPeopleByPhone(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a person and lead first
        $phone = '809' . fake()->randomNumber(7, true);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        $people->addPhone($phone);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to create customer in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        sleep(5);
        // Now pull the customer by phone
        $pullPeopleAction = new PullPeopleAction($app, $company, $user);
        $pulledPeople = $pullPeopleAction->execute(phone: $phone);

        $this->assertNotEmpty($pulledPeople);
        $this->assertInstanceOf(People::class, $pulledPeople);
        $this->assertNotNull($pulledPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
    }

    /**
     * Test pulling people by ID.
     */
    public function testPullPeopleById(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a person and lead first
        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to create customer
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $lead->refresh();
        $people = $lead->people;
        $customerId = $people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);
        $this->assertNotNull($customerId);

        sleep(5);
        // Now pull the customer by ID
        $pullPeopleAction = new PullPeopleAction($app, $company, $user);
        $pulledPeople = $pullPeopleAction->execute(customerId: $customerId);

        $this->assertInstanceOf(People::class, $pulledPeople);
        $this->assertEquals($customerId, $pulledPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
    }

    /**
     * Test searching customers.
     * Note: DriveCentric API requires at least one search filter (email, phone, firstName, or lastName).
     */
    public function testSearchCustomers(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        $customerService = new CustomerService($company, $app);

        // Search for customers - API requires at least one filter
        $customers = $customerService->search([
            'limit' => 10,
            'firstName' => 'test',
        ]);

        $this->assertIsArray($customers);
    }

    /**
     * Test getting customers with pagination.
     * Note: DriveCentric API requires at least one search filter (email, phone, firstName, or lastName).
     */
    public function testGetCustomersWithPagination(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        $customerService = new CustomerService($company, $app);

        // Get customers with pagination - must include at least one filter
        $customers = $customerService->getCustomers(
            offset: 0,
            limit: 5,
            firstName: 'test' // API requires at least one search parameter
        );

        $this->assertIsArray($customers);
    }

    /**
     * Test pulling people with their associated lead by email.
     */
    public function testPullPeopleLeadByEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a person with unique email
        $email = 'test+' . fake()->unique()->userName . '@kanvas.dev';

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        $people->addEmail($email);

        // Create a lead for this person
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to create customer and deal in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        sleep(5);

        // Now pull both people and lead by email
        $pullPeopleLeadAction = new PullPeopleLeadAction($app, $company, $user);
        $result = $pullPeopleLeadAction->execute(email: $email);

        $this->assertInstanceOf(Lead::class, $result);
        $this->assertNotNull($result->people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
        $this->assertNotNull($result->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
    }

    /**
     * Test pulling people with their associated lead by phone.
     */
    public function testPullPeopleLeadByPhone(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a person with unique phone
        $phone = '809' . fake()->randomNumber(7, true);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        $people->addPhone($phone);

        // Create a lead for this person
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        // Push lead to create customer and deal in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        sleep(5);

        // Now pull both people and lead by phone
        $pullPeopleLeadAction = new PullPeopleLeadAction($app, $company, $user);
        $result = $pullPeopleLeadAction->execute(phone: $phone);

        $this->assertInstanceOf(People::class, $result->people);
        $this->assertInstanceOf(Lead::class, $result);
        $this->assertNotNull($result->people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
        $this->assertNotNull($result->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
    }
}
