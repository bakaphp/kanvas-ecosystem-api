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
                'drivers_license' => 'D1234567',
                'drivers_license_state' => 'il',
            ],
            'housing' => [
                'address' => '123 Main St',
                'city' => ['name' => 'Chicago'],
                'state' => ['code' => 'IL'],
                'zip_code' => '60601',
                'residence_type' => 'Rent',
                'rent' => 1800,
                'time_at_address' => '3.6',
            ],
            'financial' => [
                'current_employer' => 'ABC Motors',
                'employment_status' => 'Employed',
                'current_employment_title' => 'Manager',
                'gross_income' => 6500,
                'income_interval' => 'monthly',
                'current_employer_phone' => '312-555-9999',
                'years_at_current_employment' => '3.6',
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
        $this->assertArrayNotHasKey('PURCHASEPRICE', $capturedPayload);
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
