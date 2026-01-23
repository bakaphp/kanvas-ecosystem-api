<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasDriveCentricConfiguration;
use Tests\TestCase;

final class CustomerServiceTest extends TestCase
{
    use HasDriveCentricConfiguration;

    /**
     * Helper to create a customer via lead (the only way in DriveCentric).
     */
    private function createCustomerViaLead(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $email = 'test+' . fake()->unique()->userName . '@kanvas.dev';
        $phone = '809' . fake()->randomNumber(7, true);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        $people->addEmail($email);
        $people->addPhone($phone);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $people->refresh();
        $customerId = $people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);

        return [
            'people' => $people,
            'lead' => $lead,
            'email' => $email,
            'phone' => $phone,
            'customerId' => $customerId,
        ];
    }

    /**
     * Test searching for a customer by email.
     */
    public function testSearchCustomerByEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a customer via lead
        $data = $this->createCustomerViaLead();

        $customerService = new CustomerService($company, $app);

        sleep(5);
        // Search by email
        $foundCustomer = $customerService->getCustomerByEmail($data['email']);

        $this->assertNotNull($foundCustomer);
        $this->assertIsArray($foundCustomer);
    }

    /**
     * Test searching for a customer by phone.
     */
    public function testSearchCustomerByPhone(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a customer via lead
        $data = $this->createCustomerViaLead();

        $customerService = new CustomerService($company, $app);
        sleep(5);

        // Search by phone
        $foundCustomer = $customerService->getCustomerByPhone($data['phone']);

        $this->assertNotNull($foundCustomer);
        $this->assertIsArray($foundCustomer);
    }

    /**
     * Test getting a customer by ID.
     */
    public function testGetCustomerById(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a customer via lead
        $data = $this->createCustomerViaLead();

        $this->assertNotNull($data['customerId'], 'Customer ID should be set after lead creation');

        sleep(5);
        $customerService = new CustomerService($company, $app);

        // Get by ID
        $foundCustomer = $customerService->getCustomerById($data['customerId']);

        $this->assertNotEmpty($foundCustomer);
        $this->assertIsArray($foundCustomer);
    }

    /**
     * Test searching customers with pagination.
     */
    public function testSearchCustomers(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        $customerService = new CustomerService($company, $app);

        // Search with pagination
        $customers = $customerService->getCustomers(offset: 0, limit: 10, email: fake()->email);

        $this->assertIsArray($customers);
    }

    /**
     * Test updating an existing customer.
     */
    public function testUpdateCustomer(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a customer via lead
        $data = $this->createCustomerViaLead();
        $customerId = $data['customerId'];

        $this->assertNotNull($customerId);

        $customerService = new CustomerService($company, $app);

        // Update customer
        $updateData = [
            'firstName' => 'UpdatedName',
            'lastName' => 'UpdatedLastName',
            'customerType' => 'Individual',
        ];

        $updatedCustomer = $customerService->updateCustomer($customerId, $updateData);

        $this->assertNotEmpty($updatedCustomer);
    }

    /**
     * Test getting customers by deal ID.
     */
    public function testGetCustomersByDealId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupDriveCentricClient($app, $company);

        // Create a customer via lead
        $data = $this->createCustomerViaLead();
        $lead = $data['lead'];

        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        $this->assertNotNull($dealId, 'Deal ID should be set after lead creation');

        $customerService = new CustomerService($company, $app);

        // Get customers by deal ID
        $customers = $customerService->getCustomersByDealId($dealId);

        $this->assertIsArray($customers);
    }
}
