<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\AddActivityToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddCommentToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddCreditAppToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddTradeInToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\AddVehicleOfInterestToDealAction;
use Kanvas\Connectors\DriveCentric\Actions\ProcessPurchaseVehicleAction;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\Connectors\Traits\HasDriveCentricConfiguration;
use Tests\TestCase;

final class PushLeadTest extends TestCase
{
    use HasDriveCentricConfiguration;

    public function testPushLeadAction(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        //$user->set(ConfigurationEnum::getUserKey($company), 'd67d5406-e126-4ef9-841f-a42aa93039eb');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        $pushLeadAction = new PushLeadAction($lead);
        $response = $pushLeadAction->execute();

        $this->assertNotEmpty($response);
        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
        $this->assertNotNull($lead->people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));
    }

    public function testUpdateLeadAction(): void
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

        // First push
        $pushLeadAction = new PushLeadAction($lead);
        $response = $pushLeadAction->execute();

        $this->assertNotEmpty($response);
        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        $this->assertNotNull($dealId);

        // Update lead title
        $lead->title = 'Updated Lead Title';
        $lead->save();

        // Push again (update)
        $pushLeadAction = new PushLeadAction($lead);
        $updatedResponse = $pushLeadAction->execute();

        $this->assertNotEmpty($updatedResponse);
        // Deal ID should remain the same
        $this->assertEquals($dealId, $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
    }

    public function testAddCommentToLead(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        // Add comment
        $addCommentAction = new AddCommentToDealAction($lead);
        $response = $addCommentAction->execute('Customer is interested in financing options');

        $this->assertNotEmpty($response);
    }

    public function testAddActivityToLead(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        // Add activity
        $addActivityAction = new AddActivityToDealAction($lead);
        $response = $addActivityAction->execute(
            title: 'Follow-up call',
            content: 'Discussed financing options and vehicle availability'
        );

        $this->assertNotEmpty($response);
        $this->assertArrayHasKey('activities', $response);
    }

    public function testAddCreditAppToLead(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first to create the customer in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));
        $this->assertNotNull($lead->people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value));

        // Create message with credit app data (using fake data)
        $creditAppMessageData = [
            'visitor_id' => fake()->uuid(),
            'engagement_status' => 'submitted',
            'status' => 'submitted',
            'verb' => 'credit-app',
            'text' => 'Credit App',
            'source' => 'web',
            'data' => [
                'form' => [
                    'personal' => [
                        'first_name' => fake()->firstName(),
                        'middle_name' => '',
                        'last_name' => fake()->lastName(),
                        'dob' => '15-March-1985',
                        'ssn' => '555123456',
                        'mobile_number' => '5551234567',
                        'home_number' => '',
                        'email' => fake()->safeEmail(),
                        'drivers_license' => 'DL' . fake()->numerify('########'),
                        'drivers_license_state' => [
                            'id' => '3626',
                            'name' => 'Indiana',
                            'code' => 'IN',
                        ],
                    ],
                    'housing' => [
                        'address' => fake()->streetAddress(),
                        'state' => [
                            'id' => '3626',
                            'name' => 'Indiana',
                            'code' => 'IN',
                        ],
                        'city' => fake()->city(),
                        'county' => 'Lake',
                        'zip_code' => '46307',
                        'residence_type' => 'Mortgage',
                        'rent' => '1500',
                        'time_at_address' => '3.6',
                        'previous_address' => fake()->streetAddress(),
                        'previous_state' => [
                            'id' => '3614',
                            'name' => 'Illinois',
                            'code' => 'IL',
                        ],
                        'previous_city' => fake()->city(),
                        'previous_zip_code' => '60601',
                        'previous_time_at_address' => '2.0',
                    ],
                    'financial' => [
                        'employment_status' => 'Full Time',
                        'current_employment_title' => 'Software Engineer',
                        'current_employer' => 'Tech Company Inc',
                        'current_employer_address_line1' => fake()->streetAddress(),
                        'state' => [
                            'name' => 'Indiana',
                            'id' => '3626',
                            'code' => 'IN',
                        ],
                        'city' => fake()->city(),
                        'zip_code' => '46307',
                        'current_employer_phone' => '5559876543',
                        'years_at_current_employment' => '5.0',
                        'previous_employer' => 'Old Tech Corp',
                        'previous_employer_phone' => '5551112222',
                        'previous_state' => [
                            'name' => 'Illinois',
                            'id' => '3614',
                            'code' => 'IL',
                        ],
                        'previous_city' => 'Chicago',
                        'previous_zip_code' => '60601',
                        'years_at_previous_employment' => '3.0',
                        'gross_income' => '85000',
                        'income_interval' => 'yearly',
                        'other_income' => '5000',
                        'other_income_source' => 'Freelance consulting',
                    ],
                ],
            ],
        ];

        // Get or create a message type
        $messageType = MessageType::firstOrCreate(
            ['name' => 'credit-app', 'apps_id' => $app->getId()],
            [
                'languages_id' => 1,
                'verb' => 'credit-app',
            ]
        );

        // Create the message
        $message = Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => $creditAppMessageData,
            'uuid' => fake()->uuid(),
        ]);

        // Execute the credit app action
        $addCreditAppAction = new AddCreditAppToDealAction($lead);
        $response = $addCreditAppAction->execute($message);

        $this->assertNotEmpty($response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('customerId', $response);
        $this->assertArrayHasKey('creditApp', $response);

        // Verify credit app was saved to people custom field
        $savedCreditApp = $lead->people->get(LeadCustomFieldEnum::CREDIT_APP->value);
        $this->assertNotNull($savedCreditApp);
        $this->assertArrayHasKey('currentResidenceHistory', $savedCreditApp);
        $this->assertArrayHasKey('currentEmploymentHistory', $savedCreditApp);
    }

    public function testAddTradeInToLead(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first to create the deal in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));

        // Create message with trade-in data (using fake data based on real structure)
        $tradeInMessageData = [
            'visitor_id' => fake()->uuid(),
            'engagement_status' => 'submitted',
            'status' => 'submitted',
            'verb' => 'add-trade',
            'text' => 'Add Trade',
            'source' => 'web',
            'data' => [
                'form' => [
                    'make' => 'GMC',
                    'model' => 'Sierra 1500',
                    'year' => 2024,
                    'vin' => strtoupper(fake()->regexify('[A-HJ-NPR-Z0-9]{17}')),
                    'body_style' => 'Crew Pickup',
                    'int_color' => 'Black',
                    'ext_color' => 'Red',
                    'cylinders' => 8,
                    'displacement' => '6.2',
                    'engine' => '8 Cylinder Engine',
                    'drive_train' => 'Four Wheel Drive',
                    'doors' => 4,
                    'trim' => 'AT4',
                    'trans' => 'Automatic',
                    'mileage' => '11989',
                    'payoff_amount' => '45000',
                    'value' => '42000',
                ],
                'comments' => [],
            ],
        ];

        // Get or create a message type
        $messageType = MessageType::firstOrCreate(
            ['name' => 'add-trade', 'apps_id' => $app->getId()],
            [
                'languages_id' => 1,
                'verb' => 'add-trade',
            ]
        );

        // Create the message
        $message = Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => $tradeInMessageData,
            'uuid' => fake()->uuid(),
        ]);

        // Execute the trade-in action
        $addTradeInAction = new AddTradeInToDealAction($lead);
        $response = $addTradeInAction->execute($message);

        $this->assertNotEmpty($response);
        $this->assertArrayHasKey('tradeIns', $response);

        // Verify trade-in was saved to lead custom field
        $savedTradeIn = $lead->get(LeadCustomFieldEnum::TRADE_IN->value);
        $this->assertNotNull($savedTradeIn);
        $this->assertArrayHasKey('vehicle', $savedTradeIn);
        $this->assertEquals('GMC', $savedTradeIn['vehicle']['make']);
        $this->assertEquals('Sierra 1500', $savedTradeIn['vehicle']['model']);
        $this->assertEquals(2024, $savedTradeIn['vehicle']['year']);
    }

    public function testProcessPurchaseVehicle(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first to create the deal in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));

        // Create message with purchase-vehicle data (using fake data with documentForms structure)
        $fakeName = fake()->name();
        $fakePhone = fake()->numerify('##########');
        $fakeEmail = fake()->safeEmail();
        $fakeAddress = fake()->streetAddress();
        $fakeCity = fake()->city();
        $fakeState = fake()->state();
        $fakeZip = fake()->postcode();
        $fakeVin = strtoupper(fake()->regexify('[A-HJ-NPR-Z0-9]{17}'));
        $fakeOdometer = (string) fake()->numberBetween(10000, 50000);
        $fakePayoffAmount = fake()->numberBetween(20000, 50000);
        $fakeStockNumber = (string) fake()->numberBetween(10000, 99999);

        $purchaseVehicleMessageData = [
            'visitor_id' => fake()->uuid(),
            'contact_uuid' => '',
            'user_uuid' => '',
            'hashtagVisited' => 'purchase-vehicle',
            'engagement_status' => 'submitted',
            'status' => 'submitted',
            'link' => 'https://fakedomaintest.com/purchase-vehicle',
            'link_preview' => 'https:/fakeshort.app/' . fake()->regexify('[a-zA-Z0-9]{6}'),
            'data' => [
                'documentForms' => [
                    [
                        'form' => [
                            'contact.name' => $fakeName,
                            'contact.phone' => $fakePhone,
                            'contact.email' => $fakeEmail,
                            'contact.address.address' => $fakeAddress,
                            'contact.address.city' => $fakeCity,
                            'contact.address.state' => $fakeState,
                            'contact.address.zip' => $fakeZip,
                            'contact.county' => '',
                            'lead.trade-in.year' => '2023',
                            'lead.trade-in.make' => 'BMW',
                            'lead.trade-in.model' => 'X5',
                            'lead.trade-in.odometer' => $fakeOdometer,
                            'lead.trade-in.vin' => $fakeVin,
                            'tradein.payment' => 'lease',
                            'lead.payoff.bank.name' => 'Test Bank FS',
                            'lead.payoff.bank.amount' => $fakePayoffAmount,
                            'car.title' => 'yes',
                            'damage' => 'No',
                            'tire.condition' => 'Good',
                            'lead.sold-vehicle.year' => 2026,
                            'lead.sold-vehicle.make' => 'BMW',
                            'lead.sold-vehicle.model' => 'X7',
                            'lead.sold-vehicle.condition' => 'New',
                            'lead.sold-vehicle.stk_number' => $fakeStockNumber,
                            'payment.method' => 'lease',
                            'downpayment' => 0,
                            'credit' => '',
                            'lease.term' => '10,000',
                            'lease.downpay' => 10000,
                            'lease.credit' => '740',
                            'tradein.mon.payment' => 0,
                            'lead.incentive_0' => 'loyalty program',
                            'lead.incentive_1' => '',
                            'lead.incentive_2' => '',
                            'lead.incentive_3' => '',
                            'lead.incentive_4' => 'corporate incentive',
                            'company.name' => fake()->company(),
                        ],
                        'linked_fields' => [
                            'contact.name' => $fakeName,
                            'contact.phone' => $fakePhone,
                            'contact.email' => $fakeEmail,
                            'contact.address.address' => $fakeAddress,
                            'contact.address.city' => $fakeCity,
                            'contact.address.state' => $fakeState,
                            'contact.address.zip' => $fakeZip,
                            'lead.trade-in.year' => '2023',
                            'lead.trade-in.make' => 'BMW',
                            'lead.trade-in.model' => 'X5',
                            'lead.trade-in.odometer' => $fakeOdometer,
                            'lead.trade-in.vin' => $fakeVin,
                            'lead.payoff.bank.name' => 'Test Bank FS',
                            'lead.payoff.bank.amount' => (string) $fakePayoffAmount,
                            'lead.sold-vehicle.year' => 2026,
                            'lead.sold-vehicle.make' => 'BMW',
                            'lead.sold-vehicle.model' => 'X7',
                            'lead.sold-vehicle.condition' => 'New',
                            'lead.sold-vehicle.stk_number' => $fakeStockNumber,
                            'prefill.total-monthly-payment' => '0.00',
                        ],
                        'status' => 'signed',
                        'filename' => 'Customer Needs Analysis.pdf',
                    ],
                ],
                'files' => [
                    'business_pdfs' => [],
                    'customer_pdfs' => [],
                    'action_files' => [],
                ],
            ],
            'source' => 'web',
            'text' => 'Purchase Vehicle',
            'lang' => '',
            'verb' => 'purchase-vehicle',
        ];

        // Get or create a message type
        $messageType = MessageType::firstOrCreate(
            ['name' => 'purchase-vehicle', 'apps_id' => $app->getId()],
            [
                'languages_id' => 1,
                'verb' => 'purchase-vehicle',
            ]
        );

        // Create the message
        $message = Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => $purchaseVehicleMessageData,
            'uuid' => fake()->uuid(),
        ]);

        // Execute the purchase vehicle action
        $processPurchaseVehicleAction = new ProcessPurchaseVehicleAction($lead);
        $response = $processPurchaseVehicleAction->execute($message);

        $this->assertNotEmpty($response);
        $this->assertArrayHasKey('tradeIns', $response);

        // Verify trade-in was saved to lead custom field
        $lead->refresh();
        $savedTradeIn = $lead->get(LeadCustomFieldEnum::TRADE_IN->value);
        $this->assertNotNull($savedTradeIn);
        $this->assertArrayHasKey('vehicle', $savedTradeIn);
        $this->assertEquals('BMW', $savedTradeIn['vehicle']['make']);
        $this->assertEquals('X5', $savedTradeIn['vehicle']['model']);
        $this->assertEquals('2023', $savedTradeIn['vehicle']['year']);
        $this->assertEquals($fakeVin, $savedTradeIn['vehicle']['vin']);
        $this->assertEquals($fakePayoffAmount, $savedTradeIn['payoffAmount']);
        $this->assertEquals('Test Bank FS', $savedTradeIn['lienholder']['name']);
    }

    public function testAddVehicleOfInterestToLead(): void
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

        $user->set(ConfigurationEnum::getUserKey($company), 'd8256337-9fe4-4671-8b18-abc36e452b86');
        $lead->leads_owner_id = $user->getId();
        $lead->save();

        // Push lead first to create the deal in DriveCentric
        $pushLeadAction = new PushLeadAction($lead);
        $pushLeadAction->execute();

        $this->assertNotNull($lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value));

        // Generate fake vehicle data
        $fakeVin1 = strtoupper(fake()->regexify('[A-HJ-NPR-Z0-9]{17}'));
        $fakeVin2 = strtoupper(fake()->regexify('[A-HJ-NPR-Z0-9]{17}'));
        $fakeStockNumber1 = (string) fake()->numberBetween(10000, 99999);
        $fakeStockNumber2 = (string) fake()->numberBetween(10000, 99999);
        $fakePrice1 = fake()->numberBetween(25000, 40000);
        $fakePrice2 = fake()->numberBetween(70000, 90000);

        // Create message with view-vehicle data (products array with interested vehicle)
        $viewVehicleMessageData = [
            'data' => [
                'products' => [
                    [
                        'id' => fake()->uuid(),
                        'name' => '2023 Jeep Grand Cherokee',
                        'interested' => true,
                        'is_new' => false,
                        'make' => 'Jeep',
                        'model' => 'Grand Cherokee',
                        'trim' => 'Limited',
                        'vin' => $fakeVin1,
                        'stock_number' => $fakeStockNumber1,
                        'mileage' => 15000,
                        'price' => $fakePrice1,
                        'year' => 2023,
                    ],
                    [
                        'id' => fake()->uuid(),
                        'name' => '2025 BMW X5',
                        'interested' => false,
                        'is_new' => true,
                        'make' => 'BMW',
                        'model' => 'X5',
                        'trim' => 'xDrive40i',
                        'vin' => $fakeVin2,
                        'stock_number' => $fakeStockNumber2,
                        'mileage' => null,
                        'price' => $fakePrice2,
                        'year' => 2025,
                    ],
                ],
            ],
            'text' => 'Share Vehicles',
            'link' => 'https://fakedomaintest.com/view-vehicle/' . fake()->uuid(),
            'source' => 'web',
            'link_preview' => 'https:/fakeshort.app/' . fake()->regexify('[a-zA-Z0-9]{6}'),
            'engagement_status' => 'submitted',
            'hashtagVisited' => 'view-vehicle',
            'visitor_id' => fake()->uuid(),
            'user_uuid' => '',
            'status' => 'submitted',
            'contact_uuid' => '',
            'verb' => 'view-vehicle',
        ];

        // Get or create a message type
        $messageType = MessageType::firstOrCreate(
            ['name' => 'view-vehicle', 'apps_id' => $app->getId()],
            [
                'languages_id' => 1,
                'verb' => 'view-vehicle',
            ]
        );

        // Create the message
        $message = Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => $viewVehicleMessageData,
            'uuid' => fake()->uuid(),
        ]);

        // Execute the vehicle of interest action
        $addVehicleOfInterestAction = new AddVehicleOfInterestToDealAction($lead);
        $response = $addVehicleOfInterestAction->execute($message);
        $this->assertNotEmpty($response);

        // Verify vehicle of interest was saved to lead custom field
        $lead->refresh();
        $savedVoi = $lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);
        $this->assertNotNull($savedVoi);
        $this->assertEquals('Jeep', $savedVoi['make']);
        $this->assertEquals('Grand Cherokee', $savedVoi['model']);
        $this->assertEquals(2023, $savedVoi['year']);
        $this->assertEquals($fakeVin1, $savedVoi['vin']);
        $this->assertEquals($fakeStockNumber1, $savedVoi['stock_number']);
        $this->assertEquals($fakePrice1, $savedVoi['price']);
        $this->assertEquals('Limited', $savedVoi['trim']);
        $this->assertFalse($savedVoi['isNew']);
    }
}
