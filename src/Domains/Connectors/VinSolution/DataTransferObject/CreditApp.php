<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\DataTransferObject;

use DateTime;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Support\Phone;
use Kanvas\Locations\Models\States;
use Kanvas\Social\Messages\Models\Message;
use Spatie\LaravelData\Data;
use Throwable;

class CreditApp extends Data
{
    public function __construct(
        public readonly array $address,
        public readonly array $personalInformation,
        public readonly array $licenseData
    ) {
    }

    public static function fromMessage(Message $message): self
    {
        $company = $message->company;
        $message = $message->getMessage();
        $formData = $message['data']['form'];
        $durationAtAddress = explode('.', $formData['housing']['time_at_address'] ?? '');
        $durationAtJob = explode('.', $formData['financial']['years_at_current_employment'] ?? '');
        $durationAtPreviousJob = explode('.', $formData['financial']['years_at_previous_employment'] ?? '');

        try {
            $contactDOB = isset($formData['personal']['dob']) && ! empty($formData['personal']['dob']) ? new DateTime($formData['personal']['dob']) : null;
        } catch (Throwable $e) {
            $contactDOB = null;
        }

        $employContact = preg_replace('/\D+/', '', $formData['financial']['current_employer_phone'] ?? '');
        $previousEmployerStateId = $formData['financial']['previous_state']['id'] ?? 0;
        $previousEmployersState = $previousEmployerStateId > 0 ? States::find($previousEmployerStateId) : null;
        $currentEmployerStateId = $formData['financial']['state']['id'] ?? 0;
        $currentEmployerState = $currentEmployerStateId > 0 ? States::find($currentEmployerStateId) : null;
        $currentEmployerPhoneNumber = strlen(Phone::removeUSCountryCode($formData['financial']['current_employer_phone'])) === 10 ? Phone::removeUSCountryCode($formData['financial']['current_employer_phone']) : '';
        $previousEmployerPhoneNumber = strlen(Phone::removeUSCountryCode($formData['financial']['previous_employer_phone'])) === 10 ? Phone::removeUSCountryCode($formData['financial']['previous_employer_phone']) : '';
        $defaultState = $company->get(ConfigurationEnum::DEFAULT_STATE_KEY->value) ?? 'FL';

        $result = [
            'address' => [
                [
                    'AddressId' => 0,
                    'AddressType' => 'Primary',
                    'StreetAddress' => $formData['housing']['address'] ?? '',
                    'StreetAddress2' => $formData['housing']['address_line2'] ?? '',
                    'City' => $formData['housing']['city']['name'] ?? ($formData['housing']['city'] ?? ''),
                    'State' => $formData['housing']['state']['code'] ?? '',
                    ///'County' => $formData['housing']['county'] ?? 'N/A',
                    'PostalCode' => ! empty($formData['housing']['zip_code']) ? static::cleanZipCode((string) $formData['housing']['zip_code']) : '',
                    'Duration' => isset($formData['housing']['time_at_address']) && ! empty($formData['housing']['time_at_address']) ? (string) $formData['housing']['time_at_address'] : '0',
                ],
            ],
            'personalInformation' => [
                'ContactId' => 0,
                'Ssn' => $formData['personal']['ssn'] ?? '',
                'HousingInformation' => [
                    'HousingType' => static::homeTypeMapper($formData['housing']['residence_type'] ?? ''),
                    'DurationYears' => (int) ($durationAtAddress[0] ?? 0),
                    'DurationMonths' => isset($durationAtAddress[1]) ? (int) $durationAtAddress[1] : 0,
                    'Expense' => (float) ($formData['housing']['rent'] ?? 0),
                ],
                'CurrentEmploymentInformation' => [
                    'JobTitle' => $formData['financial']['current_employment_title'] ?? '',
                    'EmploymentStatusType' => static::employerStatusMapper($formData['financial']['employment_status'] ?? ''),
                    'DurationYears' => (int) ($durationAtJob[0] ?? 0),
                    'DurationMonths' => isset($durationAtJob[1]) ? (int) $durationAtJob[1] : 0,
                    'EmployerName' => $formData['financial']['current_employer'] ?? '',
                    'IncomeType' => static::incomeTypeMapper($formData['financial']['income_interval'] ?? ''),
                    'Income' => (float) ($formData['financial']['gross_income'] ?? 0),
                    'EmployerContactPhone' => $currentEmployerPhoneNumber ?? '',
                    'EmployerAddress' => [
                        'StreetAddress' => $formData['financial']['current_employer_address_line1'] ?? '',
                        'StreetAddress2' => $formData['financial']['current_employer_address_line2'] ?? '',
                        'City' => $formData['financial']['city'] ?? '',
                        'State' => $currentEmployerState?->code ?? $defaultState,
                        'PostalCode' => ! empty($formData['financial']['zip_code']) ? static::cleanZipCode((string) $formData['financial']['zip_code']) : '',
                    ],
                ],
                'PreviousEmploymentInformation' => [
                    'EmployerName' => $formData['financial']['previous_employer'] ?? '',
                    'DurationYears' => (int) ($durationAtPreviousJob[0] ?? 0),
                    'DurationMonths' => isset($durationAtPreviousJob[1]) ? (int) $durationAtPreviousJob[1] : 0,
                    'EmployerContactPhone' => $previousEmployerPhoneNumber ?? '',
                    'EmployerAddress' => [
                        'StreetAddress' => $formData['financial']['previous_employer_address_line1'] ?? '',
                        'StreetAddress2' => $formData['financial']['previous_employer_address_line2'] ?? '',
                        'City' => $formData['financial']['previous_city']['name'] ?? '',
                        'State' => $previousEmployerStateId > 0 ? ($previousEmployersState?->code ?? $defaultState) : '',
                        'PostalCode' => ! empty($formData['financial']['previous_zip_code']) ? static::cleanZipCode((string) $formData['financial']['previous_zip_code']) : '',
                    ],
                ],
                'BankInformation' => [
                    'OtherMonthlyIncome' => (float) ($formData['financial']['other_income'] ?? 0),
                    'OtherMonthlyIncomeSource' => ! empty($formData['financial']['other_income_source']) ? substr($formData['financial']['other_income_source'], 0, 50) : '',
                ],
            ],
            'licenseData' => [
                'DateOfBirth' => $contactDOB instanceof DateTime ? $contactDOB->format('Y-m-d\TH:i:s\Z') : '',
            ],
        ];

        if (isset($formData['personal']['drivers_license']) && isset($formData['personal']['drivers_license_state'])) {
            $result['licenseData']['LicenseID'] = $formData['personal']['drivers_license'];
            $result['licenseData']['Country'] = 'US';
            $result['licenseData']['State'] = isset($formData['personal']['drivers_license_state']['code']) ? $formData['personal']['drivers_license_state']['code'] : $formData['personal']['drivers_license_state'];
        }

        //set state
        if (isset($formData['housing']['state']['id']) && $formData['housing']['state']['id'] > 0 && empty($result['licenseData']['State'])) {
            $housingState = States::find($formData['housing']['state']['id']);
            $result['licenseData']['State'] = $housingState?->code ?? $defaultState;
        }

        if (isset($formData['housing']['previous_address']) && ! empty($formData['housing']['previous_address'])) {
            $result['address'][1]['AddressId'] = 1;
            $result['address'][1]['AddressType'] = 'Previous';
            $result['address'][1]['StreetAddress'] = $formData['housing']['previous_address'] ?? '';
            $result['address'][1]['StreetAddress2'] = $formData['housing']['previous_address_line2'] ?? '';
            $result['address'][1]['State'] = $formData['housing']['previous_state']['code'] ?? '';
            $result['address'][1]['City'] = $formData['housing']['previous_city']['name'] ?? ($formData['housing']['previous_city'] ?? '');
            $result['address'][1]['PostalCode'] = $formData['housing']['previous_zip_code'] ?? '';
            $result['address'][1]['Duration'] = isset($formData['housing']['previous_time_at_address']) && ! empty($formData['housing']['previous_time_at_address']) ? (string) $formData['housing']['previous_time_at_address'] : '0';
        }

        return new self(
            address: $result['address'],
            personalInformation: $result['personalInformation'],
            licenseData: $result['licenseData']
        );
    }

    protected static function cleanZipCode(string $zipCode): string
    {
        //limit to only 5 digits to 9 digits
        return preg_replace('/\D/', '', $zipCode);
    }

    /**
    * Get the employ status from vin solution.
    */
    protected static function employerStatusMapper(string $status): string
    {
        switch (strtolower($status)) {
            case 'part time':
            case 'contract':
            case 'full time':
            case 'seasonal':
            case 'temporary':
            case 'self employed':
                return 'Employed';
            case 'not applicable':
                return 'Unemployed';
            case 'military':
                return 'Active Military';
            case 'retired':
                return 'Retired';
            default:
                return 'Other';
        }
    }

    /**
     * Get the employ status from vin solution.
     */
    protected static function homeTypeMapper(string $type): string
    {
        switch (strtolower($type)) {
            case 'own':
                return 'Homeowner';
            case 'rent':
                return 'Rent';
            case 'relative':
                return 'Family';
            default:
                return 'Other';
        }
    }

    /**
     * Map income type to vin solutions values.
     */
    protected static function incomeTypeMapper(string $type): string
    {
        switch (strtolower($type)) {
            case 'weekly':
                return 'Weekly';
            case 'bi-weekly':
                return 'BiWeekly';
            case 'monthly':
                return 'Monthly';
            case 'yearly':
                return 'Annual';
            default:
                return 'Monthly';
        }
    }
}
