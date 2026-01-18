<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressDTO;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Social\Messages\Models\Message;
use RuntimeException;

class AddCreditAppToDealAction
{
    protected LeadService $leadService;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->leadService = new LeadService($this->lead->app, $this->lead->company);
    }

    public function execute(Message $message, ?People $people = null): array
    {
        // Use provided people or default to lead's people
        $targetPeople = $people ?? $this->lead->people;

        // Ensure customer exists in DriveCentric
        $customerId = $targetPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);

        if (! $customerId) {
            // Push lead first to create customer records
            $pushLeadAction = new PushLeadAction($this->lead);
            $pushLeadAction->execute();
            $customerId = $targetPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);
        }

        if (! $customerId) {
            throw new RuntimeException('Unable to get or create customer in DriveCentric');
        }

        $messageData = $message->getMessage();

        $creditAppData = $this->formatCreditApp($messageData);

        $targetPeople->set(LeadCustomFieldEnum::CREDIT_APP->value, $creditAppData);

        $this->updatePeopleAddress($targetPeople, $messageData);

        return $this->leadService->updateCustomerCreditApp($customerId, $creditAppData);
    }

    protected function formatCreditApp(array $messageData): array
    {
        $formData = $messageData['data']['form'] ?? [];

        // Parse duration fields (format: "years.months")
        $durationAtAddress = $this->parseDuration($formData['housing']['time_at_address'] ?? '');
        $durationAtJob = $this->parseDuration($formData['financial']['years_at_current_employment'] ?? '');
        $durationAtPreviousJob = $this->parseDuration($formData['financial']['years_at_previous_employment'] ?? '');

        // Clean phone numbers to 10 digits
        $currentEmployerPhone = $this->cleanPhoneNumber($formData['financial']['current_employer_phone'] ?? '');
        $previousEmployerPhone = $this->cleanPhoneNumber($formData['financial']['previous_employer_phone'] ?? '');

        $creditApp = [];

        // Driving License
        if (isset($formData['personal']['drivers_license']) || isset($formData['personal']['dob'])) {
            $creditApp['drivingLicense'] = [
                'driversLicenseNumber' => $formData['personal']['drivers_license'] ?? null,
                'driversLicenseState' => $this->getStateCode($formData['personal']['drivers_license_state'] ?? null),
            ];
        }

        // Current Residence History
        if (isset($formData['housing'])) {
            $creditApp['currentResidenceHistory'] = [
                'addressLine' => $formData['housing']['address'] ?? null,
                'county' => isset($formData['housing']['county']) && ! empty($formData['housing']['county'])
                    ? trim($formData['housing']['county'])
                    : null,
                'city' => $this->getCityName($formData['housing']['city'] ?? null),
                'state' => $formData['housing']['state']['code'] ?? ($formData['housing']['state'] ?? null),
                'zip' => $this->cleanZipCode($formData['housing']['zip_code'] ?? ''),
                'mortgagePayment' => (float) ($formData['housing']['rent'] ?? 0),
                'monthsInResidence' => $this->calculateTotalMonths($durationAtAddress),
                'housingStatus' => $this->mapHousingStatus($formData['housing']['residence_type'] ?? ''),
            ];
        }

        // Previous Residence History (if provided)
        if (isset($formData['housing']['previous_address']) && ! empty($formData['housing']['previous_address'])) {
            $durationAtPreviousAddress = $this->parseDuration($formData['housing']['previous_time_at_address'] ?? '');
            $creditApp['previousResidenceHistory'] = [
                'addressLine' => $formData['housing']['previous_address'] ?? null,
                'county' => null,
                'city' => $this->getCityName($formData['housing']['previous_city'] ?? null),
                'state' => $formData['housing']['previous_state']['code'] ?? ($formData['housing']['previous_state'] ?? null),
                'zip' => $this->cleanZipCode($formData['housing']['previous_zip_code'] ?? ''),
                'mortgagePayment' => null,
                'monthsInResidence' => $this->calculateTotalMonths($durationAtPreviousAddress),
                'housingStatus' => 'Undefined',
            ];
        }

        // Current Employment History
        if (isset($formData['financial'])) {
            $creditApp['currentEmploymentHistory'] = [
                'status' => $this->mapEmploymentStatus($formData['financial']['employment_status'] ?? ''),
                'name' => $formData['financial']['current_employer'] ?? null,
                'occupation' => $formData['financial']['current_employment_title'] ?? null,
                'monthsOfEmployment' => $this->calculateTotalMonths($durationAtJob),
                'salary' => (float) ($formData['financial']['gross_income'] ?? 0),
                'salaryType' => $this->mapSalaryType($formData['financial']['income_interval'] ?? ''),
                'addressLine1' => $formData['financial']['current_employer_address_line1'] ?? null,
                'addressLine2' => $formData['financial']['current_employer_address_line2'] ?? null,
                'city' => $formData['financial']['city'] ?? null,
                'state' => $this->getStateCode($formData['financial']['state'] ?? null),
                'zip' => $this->cleanZipCode($formData['financial']['zip_code'] ?? ''),
                'county' => null,
                'phoneNumber' => $currentEmployerPhone,
            ];
        }

        // Previous Employment History
        if (isset($formData['financial']['previous_employer']) && ! empty($formData['financial']['previous_employer'])) {
            $creditApp['previousEmploymentHistory'] = [
                'status' => 'Employed',
                'name' => $formData['financial']['previous_employer'] ?? null,
                'occupation' => null,
                'monthsOfEmployment' => $this->calculateTotalMonths($durationAtPreviousJob),
                'salary' => null,
                'salaryType' => 'Undefined',
                'addressLine1' => $formData['financial']['previous_employer_address_line1'] ?? null,
                'addressLine2' => $formData['financial']['previous_employer_address_line2'] ?? null,
                'city' => $this->getCityName($formData['financial']['previous_city'] ?? null),
                'state' => $this->getStateCode($formData['financial']['previous_state'] ?? null),
                'zip' => $this->cleanZipCode($formData['financial']['previous_zip_code'] ?? ''),
                'county' => null,
                'phoneNumber' => $previousEmployerPhone,
            ];
        }

        // Insurance object (includes other income and bank information per API spec)
        $insurance = [];

        if (isset($formData['financial']['other_income']) && $formData['financial']['other_income'] > 0) {
            $insurance['otherIncomeAmount'] = (float) $formData['financial']['other_income'];
            $insurance['otherIncomeSource'] = ! empty($formData['financial']['other_income_source'])
                ? substr($formData['financial']['other_income_source'], 0, 50)
                : null;
        }

        if (isset($formData['financial']['bank_name']) && ! empty($formData['financial']['bank_name'])) {
            $insurance['bank'] = $formData['financial']['bank_name'];
            $insurance['bankContact'] = $formData['financial']['bank_contact'] ?? null;
        }

        if (! empty($insurance)) {
            $creditApp['insurance'] = $insurance;
        }

        return $creditApp;
    }

    protected function updatePeopleAddress(People $people, array $messageData): void
    {
        $formData = $messageData['data']['form'] ?? [];

        if (! isset($formData['housing']['address'])) {
            return;
        }

        // Add/update primary address
        $addressDto = AddressDTO::fromArray([
            'address' => $formData['housing']['address'] ?? '',
            'address_2' => $formData['housing']['address_line2'] ?? null,
            'city' => $this->getCityName($formData['housing']['city'] ?? null),
            'state' => $formData['housing']['state']['code'] ?? ($formData['housing']['state'] ?? null),
            'zip' => $formData['housing']['zip_code'] ?? null,
            'duration' => $formData['housing']['time_at_address'] ?? 0,
            'is_default' => true,
        ]);

        $people->addDefaultAddress($addressDto);

        // Add previous address if provided
        if (isset($formData['housing']['previous_address']) && ! empty($formData['housing']['previous_address'])) {
            $previousAddressDto = AddressDTO::fromArray([
                'address' => $formData['housing']['previous_address'] ?? '',
                'address_2' => $formData['housing']['previous_address_line2'] ?? null,
                'city' => $this->getCityName($formData['housing']['previous_city'] ?? null),
                'state' => $formData['housing']['previous_state']['code'] ?? ($formData['housing']['previous_state'] ?? null),
                'zip' => $formData['housing']['previous_zip_code'] ?? null,
                'duration' => $formData['housing']['previous_time_at_address'] ?? 0,
                'address_type_id' => AddressType::getByName(AddressTypeEnum::PREVIOUS_HOME->value, $people->app)?->getId(),
            ]);

            $people->addAddress($previousAddressDto);
        }
    }

    protected function parseDuration(string $duration): array
    {
        if (empty($duration)) {
            return ['years' => 0, 'months' => 0];
        }

        $parts = explode('.', (string) $duration);

        return [
            'years' => (int) ($parts[0] ?? 0),
            'months' => (int) ($parts[1] ?? 0),
        ];
    }

    protected function calculateTotalMonths(array $duration): int
    {
        return ($duration['years'] * 12) + $duration['months'];
    }

    protected function cleanPhoneNumber(string $phone): ?string
    {
        // Remove country code and non-numeric characters
        $cleaned = preg_replace('/\D/', '', $phone);

        // Remove leading 1 if 11 digits (US country code)
        if (strlen($cleaned) === 11 && str_starts_with($cleaned, '1')) {
            $cleaned = substr($cleaned, 1);
        }

        return strlen($cleaned) === 10 ? $cleaned : null;
    }

    /**
     * Clean ZIP code to numeric only.
     */
    protected function cleanZipCode(string $zipCode): ?string
    {
        if (empty($zipCode)) {
            return null;
        }

        return preg_replace('/\D/', '', $zipCode);
    }

    /**
     * Get state code from state data (object or string).
     */
    protected function getStateCode(array|null|string $state): ?string
    {
        if (is_array($state)) {
            return $state['code'] ?? null;
        }

        if (is_string($state) && strlen($state) === 2) {
            return strtoupper($state);
        }

        return null;
    }

    /**
     * Get city name from city data (object or string).
     */
    protected function getCityName(array|null|string $city): ?string
    {
        if (is_array($city)) {
            return $city['name'] ?? null;
        }

        return is_string($city) ? $city : null;
    }

    /**
     * Map housing status to DriveCentric enum.
     * HousingStatus: Undefined, Mortgage, OwnOutright, Rent, Family, Military, Other
     */
    protected function mapHousingStatus(string $type): string
    {
        return match (strtolower($type)) {
            'own', 'own outright' => 'OwnOutright',
            'mortgage' => 'Mortgage',
            'rent' => 'Rent',
            'relative', 'family', 'with family' => 'Family',
            'military' => 'Military',
            default => 'Other',
        };
    }

    /**
     * Map employment status to DriveCentric enum.
     * EmploymentStatus: Undefined, Employed, Unemployed, Retired, ActiveMilitary, RetiredMilitary, Other, Student
     */
    protected function mapEmploymentStatus(string $status): string
    {
        return match (strtolower($status)) {
            'part time', 'contract', 'full time', 'seasonal', 'temporary', 'self employed', 'employed' => 'Employed',
            'not applicable', 'unemployed' => 'Unemployed',
            'military', 'active military' => 'ActiveMilitary',
            'retired military' => 'RetiredMilitary',
            'retired' => 'Retired',
            'student' => 'Student',
            default => 'Other',
        };
    }

    /**
     * Map salary/income type to DriveCentric enum.
     * SalaryFrequency: Undefined, Monthly, Weekly, BiWeekly, Annually
     */
    protected function mapSalaryType(string $type): string
    {
        return match (strtolower($type)) {
            'weekly' => 'Weekly',
            'bi-weekly', 'biweekly' => 'BiWeekly',
            'monthly' => 'Monthly',
            'yearly', 'annually', 'annual' => 'Annually',
            default => 'Monthly',
        };
    }
}
