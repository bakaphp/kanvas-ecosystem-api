<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Credit700;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplication;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Services\CreditApplicationService;
use Kanvas\Guild\Customers\Models\People;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SubmitCreditApplicationTest extends TestCase
{
    private function formData(): array
    {
        return [
            'personal' => [
                'first_name' => 'John',
                'last_name' => 'Smith',
                'ssn' => '123-45-6789',
                'dob' => '14-April-1965',
                'email' => 'john@example.com',
                'mobile_number' => '312-555-1234',
                'home_number' => '312-555-0000',
                'drivers_license' => 'D1234567',
                'drivers_license_state' => 'il',
            ],
            'housing' => [
                'address' => '123 Main St',
                'city' => ['name' => 'Chicago'],
                'state' => ['code' => 'IL'],
                'zip_code' => '60601',
                'residence_type' => 'Rent',
                'rent' => 1850.5,
                'time_at_address' => '1.2',
                'previous_address' => '4605 riverbend Ct.',
                'previous_city' => 'Tifton',
                'previous_state' => ['code' => 'GA'],
                'previous_zip_code' => '31794',
                'previous_time_at_address' => '7.4',
            ],
            'financial' => [
                'current_employer' => 'ABC Motors',
                'employment_status' => 'Employed',
                'current_employment_title' => 'Manager',
                'gross_income' => 6500,
                'income_interval' => 'monthly',
                'current_employer_phone' => '312-555-9999',
                'years_at_current_employment' => '3.6',
                'previous_employer' => 'XYZ Corp',
                'previous_employment_title' => 'Assistant Manager',
                'previous_employer_phone' => '312-555-8888',
                'years_at_previous_employment' => '2.9',
                'other_income' => 400,
                'other_income_source' => 'Freelance',
            ],
        ];
    }

    public function testFromFormMapsApplicantFields(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $application = CreditApplication::from($this->formData(), $people);

        $this->assertSame('John Smith', $application->name);
        $this->assertSame('123-45-6789', $application->ssn);
        $this->assertSame('04/14/1965', $application->dob);
        $this->assertSame('123 Main St', $application->address);
        $this->assertSame('Chicago', $application->city);
        $this->assertSame('IL', $application->state);
        $this->assertSame('60601', $application->zip);
        $this->assertSame('ABC Motors', $application->employer);
        $this->assertSame(3, $application->employmentYears);
        $this->assertSame(6, $application->employmentMonths);
        $this->assertSame(6500.0, $application->monthlyIncome);
        $this->assertSame('Rent', $application->housingType);
        $this->assertSame('IL', $application->driversLicenseState);
        $this->assertSame(1, $application->currentAddressPeriod);
        $this->assertSame('4605 riverbend Ct.', $application->previousAddress);
        $this->assertSame('Tifton', $application->previousCity);
        $this->assertSame('GA', $application->previousState);
        $this->assertSame('31794', $application->previousZip);
        $this->assertSame(7, $application->previousAddressPeriod);
        $this->assertSame('312-555-0000', $application->phone);
        $this->assertSame('312-555-1234', $application->mobileNumber);
        $this->assertSame('XYZ Corp', $application->previousEmployer);
        $this->assertSame(3, $application->previousEmploymentYears);
        $this->assertSame('Assistant Manager', $application->previousEmploymentTitle);
        $this->assertSame('312-555-8888', $application->previousWorkPhone);
    }

    #[DataProvider('addressPeriodProvider')]
    public function testFromFormNormalizesAddressPeriod(string $timeAtAddress, ?int $expectedPeriod): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $formData = $this->formData();
        $formData['housing']['time_at_address'] = $timeAtAddress;

        $this->assertSame(
            $expectedPeriod,
            CreditApplication::from($formData, $people)->currentAddressPeriod
        );
    }

    public static function addressPeriodProvider(): array
    {
        return [
            'whole years, no month remainder' => ['3.0', 3],
            'months under half a year round down' => ['5.4', 5],
            'months over half a year round up' => ['5.7', 6],
            'under a year rounds to zero' => ['0.3', 0],
            'empty duration' => ['', null],
        ];
    }

    #[DataProvider('monthlyIncomeProvider')]
    public function testFromFormConvertsIncomeToMonthly(mixed $grossIncome, ?string $interval, ?float $expected): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $formData = $this->formData();
        $formData['financial']['gross_income'] = $grossIncome;
        $formData['financial']['income_interval'] = $interval;

        $this->assertSame(
            $expected,
            CreditApplication::from($formData, $people)->monthlyIncome
        );
    }

    public static function monthlyIncomeProvider(): array
    {
        return [
            'monthly passes through' => [6500, 'monthly', 6500.0],
            'yearly divided by twelve' => [78000, 'yearly', 6500.0],
            'annual alias' => ['78000', 'Annually', 6500.0],
            'bi-weekly, 26 pay periods' => [3000, 'bi-weekly', 6500.0],
            'weekly, 52 pay periods' => [1500, 'weekly', 6500.0],
            'yearly rounds to whole dollars' => [50000, 'yearly', 4167.0],
            'large yearly stays within the 7 character cap' => [155000, 'yearly', 12917.0],
            'missing interval treated as monthly' => [6500, null, 6500.0],
            'missing income stays null' => ['', 'yearly', null],
        ];
    }

    public function testFromFormOmitsPreviousAddressWhenNotProvided(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $formData = $this->formData();
        unset(
            $formData['housing']['previous_address'],
            $formData['housing']['previous_city'],
            $formData['housing']['previous_state'],
            $formData['housing']['previous_zip_code'],
            $formData['housing']['previous_time_at_address'],
        );

        $application = CreditApplication::from($formData, $people);

        $this->assertNull($application->previousAddress);
        $this->assertNull($application->previousCity);
        $this->assertNull($application->previousState);
        $this->assertNull($application->previousZip);
        $this->assertNull($application->previousAddressPeriod);
    }

    /**
     * The form textarea reaches us with the line break half-decoded, so the raw value is
     * "350 Monon Blvdhex0d;Hex0a;Apt 315" — that must never go out to the bureau as-is.
     */
    public function testFromFormFlattensAMangledMultiLineStreet(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $formData = $this->formData();
        $formData['housing']['address'] = '350 Monon Blvdhex0d;Hex0a;Apt 315';

        $this->assertSame(
            '350 Monon Blvd Apt 315',
            CreditApplication::from($formData, $people)->address
        );
    }

    public function testSubmitToRouteOneSendsSaveOnlyPayload(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::ACCOUNT->value, 'test_account');
        $app->set(ConfigurationEnum::PASSWORD->value, 'test_password');
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'test_client_id');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'test_client_secret');

        $capturedPayload = null;

        $mockClient = Mockery::mock('overload:' . Client::class);
        $mockClient->shouldReceive('post')
            ->with('/Request', Mockery::type('array'))
            ->andReturnUsing(function (string $path, array $data) use (&$capturedPayload): array {
                $capturedPayload = $data;

                return [
                    'XML_Version' => 'Iframe 2.0',
                    'XML_Report' => [
                        'Transid' => '700DSO-198906135',
                        'Token' => '700DSO-04f4c5c0-1858-4c9a-b4f6-3ac7ababb5cc',
                    ],
                ];
            });

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $application = CreditApplication::from($this->formData(), $people);

        $result = new CreditApplicationService($app)->submitToRouteOne($application);

        $this->assertTrue($result['success']);
        $this->assertSame('700DSO-198906135', $result['transaction_id']);
        $this->assertSame('700DSO-04f4c5c0-1858-4c9a-b4f6-3ac7ababb5cc', $result['token']);
        $this->assertSame('SAVEONLY', $capturedPayload['PRODUCT']);
        $this->assertSame('R1', $capturedPayload['LOS_SYSTEM']);
        $this->assertSame('PCCREDIT', $capturedPayload['PROCESS']);
        $this->assertSame('test_account', $capturedPayload['ACCOUNT']);
        $this->assertSame('123-45-6789', $capturedPayload['SSN']);
        $this->assertSame('6500', $capturedPayload['MINCOME']);
        $this->assertSame('1851', $capturedPayload['HOUSINGPAY']);
        $this->assertSame('1', $capturedPayload['CURRENTADDRESSPERIOD']);
        $this->assertSame('4605 riverbend Ct.', $capturedPayload['PREVADDRESS']);
        $this->assertSame('Tifton', $capturedPayload['PREVCITY']);
        $this->assertSame('GA', $capturedPayload['PREVSTATE']);
        $this->assertSame('31794', $capturedPayload['PREVZIP']);
        $this->assertSame('7', $capturedPayload['PREVADDRESSPERIOD']);
        $this->assertSame('312-555-0000', $capturedPayload['PHONE']);
        $this->assertSame('312-555-1234', $capturedPayload['MOBILE']);
        $this->assertSame('XYZ Corp', $capturedPayload['PREVEMPLOYER']);
        $this->assertSame('3', $capturedPayload['PREVEMPLOYMENTYEARS']);
        $this->assertSame('Assistant Manager', $capturedPayload['PREVOCCUPATION']);
        $this->assertSame('312-555-8888', $capturedPayload['PREVWORKPHONE']);
        $this->assertArrayNotHasKey('PURCHASEPRICE', $capturedPayload);
    }

    public function testSubmitToRouteOneOmitsPreviousAddressWhenNotProvided(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $app->set(ConfigurationEnum::ACCOUNT->value, 'test_account');
        $app->set(ConfigurationEnum::PASSWORD->value, 'test_password');
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'test_client_id');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'test_client_secret');

        $capturedPayload = null;

        $mockClient = Mockery::mock('overload:' . Client::class);
        $mockClient->shouldReceive('post')
            ->with('/Request', Mockery::type('array'))
            ->andReturnUsing(function (string $path, array $data) use (&$capturedPayload): array {
                $capturedPayload = $data;

                return [
                    'XML_Version' => 'Iframe 2.0',
                    'XML_Report' => [
                        'Transid' => '700DSO-198906135',
                        'Token' => '700DSO-04f4c5c0-1858-4c9a-b4f6-3ac7ababb5cc',
                    ],
                ];
            });

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $formData = $this->formData();
        unset(
            $formData['housing']['previous_address'],
            $formData['housing']['previous_city'],
            $formData['housing']['previous_state'],
            $formData['housing']['previous_zip_code'],
            $formData['housing']['previous_time_at_address'],
        );

        $application = CreditApplication::from($formData, $people);

        new CreditApplicationService($app)->submitToRouteOne($application);

        $this->assertArrayNotHasKey('PREVADDRESS', $capturedPayload);
        $this->assertArrayNotHasKey('PREVCITY', $capturedPayload);
        $this->assertArrayNotHasKey('PREVSTATE', $capturedPayload);
        $this->assertArrayNotHasKey('PREVZIP', $capturedPayload);
        $this->assertArrayNotHasKey('PREVADDRESSPERIOD', $capturedPayload);
    }

    public function testSubmitToRouteOneFlagsGatewayError(): void
    {
        $app = app(Apps::class);

        $app->set(ConfigurationEnum::ACCOUNT->value, 'test_account');
        $app->set(ConfigurationEnum::PASSWORD->value, 'test_password');
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'test_client_id');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'test_client_secret');

        $mockClient = Mockery::mock('overload:' . Client::class);
        $mockClient->shouldReceive('post')
            ->with('/Request', Mockery::type('array'))
            ->andReturn(['Creditsystem_Error' => ['@attributes' => ['id' => '101'], 'message' => 'Invalid account']]);

        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $application = CreditApplication::from($this->formData(), $people);

        $result = new CreditApplicationService($app)->submitToRouteOne($application);

        $this->assertFalse($result['success']);
    }
}
