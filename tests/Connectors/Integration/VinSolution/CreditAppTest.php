<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\DataTransferObject\CreditApp;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class CreditAppTest extends TestCase
{
    public function testFullCreditAppShapeMatchesPayload(): void
    {
        $company = Companies::first();
        // pin the default state so the test is independent of company-level config drift
        $company->set(ConfigurationEnum::DEFAULT_STATE_KEY->value, 'FL');

        $message = $this->buildMessageFromPayload($this->basePayload(), $company);

        $creditApp = CreditApp::fromMessage($message);

        $this->assertSame(
            [
                [
                    'AddressId' => 0,
                    'AddressType' => 'Primary',
                    'StreetAddress' => '123 Test St',
                    'StreetAddress2' => '',
                    'City' => 'Testville',
                    'State' => 'IL',
                    'PostalCode' => '60002',
                    'Duration' => '21.0',
                ],
            ],
            $creditApp->address
        );

        $this->assertSame(
            [
                'ContactId' => 0,
                'Ssn' => '000000000',
                'PersonalDates' => [
                    ['DateType' => 'DateOfBirth', 'DateValue' => '1978-04-08T00:00:00Z'],
                ],
                'HousingInformation' => [
                    'HousingType' => 'Other',
                    'DurationYears' => 21,
                    'DurationMonths' => 0,
                    'Expense' => 2600.0,
                ],
                'CurrentEmploymentInformation' => [
                    'JobTitle' => 'QA Engineer',
                    'EmploymentStatusType' => 'Employed',
                    'DurationYears' => 28,
                    'DurationMonths' => 0,
                    'EmployerName' => 'Acme Test Corp',
                    'IncomeType' => 'Monthly',
                    'Income' => 97500.0,
                    'EmployerContactPhone' => '5555550101',
                    'EmployerAddress' => [
                        'StreetAddress' => '1 Fixture Ave',
                        'StreetAddress2' => '',
                        'City' => 'Testville',
                        'State' => 'IL',
                        'PostalCode' => '60085',
                    ],
                ],
                'PreviousEmploymentInformation' => [
                    'EmployerName' => '',
                    'DurationYears' => 0,
                    'DurationMonths' => 0,
                    'EmployerContactPhone' => '',
                    'EmployerAddress' => [
                        'StreetAddress' => '',
                        'StreetAddress2' => '',
                        'City' => '',
                        'State' => '',
                        'PostalCode' => '',
                    ],
                ],
                'BankInformation' => [
                    'OtherMonthlyIncome' => 0.0,
                    'OtherMonthlyIncomeSource' => '',
                ],
            ],
            $creditApp->personalInformation
        );

        $this->assertSame(
            [
                'DateOfBirth' => '1978-04-08T00:00:00Z',
                'State' => 'IL',
            ],
            $creditApp->licenseData
        );
    }

    public function testPreviousAddressIsAppendedWhenProvided(): void
    {
        $payload = $this->basePayload();
        $payload['data']['form']['housing']['previous_address'] = '500 Old Lane';
        $payload['data']['form']['housing']['previous_address_line2'] = 'Apt 2';
        $payload['data']['form']['housing']['previous_state'] = ['id' => '0', 'name' => 'Wisconsin', 'code' => 'WI'];
        $payload['data']['form']['housing']['previous_city'] = ['name' => 'Milwaukee'];
        $payload['data']['form']['housing']['previous_zip_code'] = '53202';
        $payload['data']['form']['housing']['previous_time_at_address'] = '3.5';

        $creditApp = CreditApp::fromMessage($this->buildMessageFromPayload($payload));

        $this->assertCount(2, $creditApp->address);
        $this->assertSame(
            [
                'AddressId' => 1,
                'AddressType' => 'Previous',
                'StreetAddress' => '500 Old Lane',
                'StreetAddress2' => 'Apt 2',
                'State' => 'WI',
                'City' => 'Milwaukee',
                'PostalCode' => '53202',
                'Duration' => '3.5',
            ],
            $creditApp->address[1]
        );
    }

    public function testDriversLicensePopulatesLicenseData(): void
    {
        $payload = $this->basePayload();
        $payload['data']['form']['personal']['drivers_license'] = 'C123456789';
        $payload['data']['form']['personal']['drivers_license_state'] = ['code' => 'IL'];

        $creditApp = CreditApp::fromMessage($this->buildMessageFromPayload($payload));

        $this->assertSame(
            [
                'DateOfBirth' => '1978-04-08T00:00:00Z',
                'LicenseID' => 'C123456789',
                'Country' => 'US',
                'State' => 'IL',
            ],
            $creditApp->licenseData
        );
    }

    public function testWithoutDobOmitsLicenseDateOfBirthAndPersonalDatesIsEmpty(): void
    {
        $payload = $this->basePayload();
        unset($payload['data']['form']['personal']['dob']);

        $creditApp = CreditApp::fromMessage($this->buildMessageFromPayload($payload));

        $this->assertSame([], $creditApp->personalInformation['PersonalDates']);
        $this->assertArrayNotHasKey(
            'DateOfBirth',
            $creditApp->licenseData,
            'empty-string DateOfBirth is what crashes VinSolution PersonalDatesContainsDateOfBirth'
        );
    }

    public function testUnparseableDobTreatedAsMissing(): void
    {
        $payload = $this->basePayload();
        $payload['data']['form']['personal']['dob'] = 'not-a-date';

        $creditApp = CreditApp::fromMessage($this->buildMessageFromPayload($payload));

        $this->assertSame([], $creditApp->personalInformation['PersonalDates']);
        $this->assertArrayNotHasKey('DateOfBirth', $creditApp->licenseData);
    }

    protected function buildMessageFromPayload(array $payload, ?Companies $company = null): Message
    {
        $message = new Message();
        $message->setRawAttributes(['message' => json_encode($payload)], sync: true);
        $message->setRelation('company', $company ?? Companies::first());

        return $message;
    }

    /**
     * Mirrors the real credit-app submission shape with obviously-synthetic PII (RFC2606 domains,
     * 555 phone numbers, zeroed SSN). Field values are deliberately deterministic so the
     * full-shape assertSame below stays stable.
     */
    protected function basePayload(): array
    {
        return [
            'visitor_id' => '00000000-0000-0000-0000-000000000000',
            'data' => [
                'form' => [
                    'personal' => [
                        'first_name' => 'Test',
                        'middle_name' => 'Q',
                        'last_name' => 'Customer',
                        'dob' => '8-April-1978',
                        'ssn' => '000000000',
                        'mobile_number' => '5555550100',
                        'email' => 'test+customer@example.test',
                    ],
                    'housing' => [
                        'address' => '123 Test St',
                        'state' => ['id' => '3625', 'name' => 'Illinois', 'code' => 'IL'],
                        'city' => 'Testville',
                        'zip_code' => '60002',
                        'residence_type' => 'Mortgage',
                        'rent' => '2600',
                        'time_at_address' => '21.0',
                    ],
                    'financial' => [
                        'employment_status' => 'Full Time',
                        'current_employment_title' => 'QA Engineer',
                        'current_employer' => 'Acme Test Corp',
                        'current_employer_address_line1' => '1 Fixture Ave',
                        'state' => ['id' => '3625', 'name' => 'Illinois', 'code' => 'IL'],
                        'city' => 'Testville',
                        'zip_code' => '60085',
                        'current_employer_phone' => '5555550101',
                        'previous_employer_phone' => '',
                        'years_at_current_employment' => '28.0',
                        'gross_income' => '97500',
                    ],
                ],
            ],
        ];
    }
}
