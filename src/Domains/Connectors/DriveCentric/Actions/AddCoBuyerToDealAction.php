<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use DateTimeInterface;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use RuntimeException;

class AddCoBuyerToDealAction
{
    protected LeadService $leadService;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->leadService = new LeadService($this->lead->app, $this->lead->company);
    }

    public function execute(People $coBuyerPeople): array
    {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            throw new RuntimeException('Cannot add co-buyer to a lead that has not been synced to DriveCentric');
        }

        $coBuyerData = $this->formatCoBuyerData($coBuyerPeople);
        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);
        $dealData['customers'][] = $coBuyerData;

        $response = $this->leadService->upsertDeal($dealData);

        $this->saveCoBuyerIdentifier($response, $coBuyerPeople);

        return $response;
    }

    protected function formatCoBuyerData(People $coBuyerPeople): array
    {
        $identifiers = [
            ['type' => 'PartnerId', 'value' => (string) $coBuyerPeople->getId()],
        ];

        $coBuyerCustomerId = $coBuyerPeople->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);
        if ($coBuyerCustomerId) {
            $identifiers[] = ['type' => 'CrmId', 'value' => $coBuyerCustomerId];
        }

        $coBuyerData = [
            'identifiers' => $identifiers,
            'isPrimaryBuyer' => false,
            'type' => 'Individual',
            'firstName' => $coBuyerPeople->firstname,
            'lastName' => $coBuyerPeople->lastname,
        ];

        if (! empty($coBuyerPeople->middlename)) {
            $coBuyerData['middleName'] = $coBuyerPeople->middlename;
        }

        if ($coBuyerPeople->dob !== null) {
            $dob = $coBuyerPeople->dob;
            if ($dob instanceof DateTimeInterface) {
                $coBuyerData['birthdate'] = $dob->format('Y-m-d');
            }
        }

        $emails = $coBuyerPeople->getEmails()->map(fn ($email) => [
            'type' => 'Home',
            'value' => $email->value,
        ])->toArray();

        if (! empty($emails)) {
            $coBuyerData['emails'] = $emails;
        }

        $phones = $coBuyerPeople->getAllPhones()->map(function ($phone) {
            $phoneType = $phone->type->name === 'Cellphone' ? 'Mobile' : 'Home';

            return [
                'type' => $phoneType,
                'value' => $phone->value,
            ];
        })->toArray();

        if (! empty($phones)) {
            $coBuyerData['phones'] = $phones;
        }

        $driverLicenseData = $coBuyerPeople->getDriverLicenseData();
        if ($driverLicenseData) {
            $coBuyerData['driversLicense'] = [
                'number' => $driverLicenseData['driversLicenseNumber'],
                'state' => $driverLicenseData['driversLicenseState'],
            ];
        }

        $creditAppData = $coBuyerPeople->get(LeadCustomFieldEnum::CREDIT_APP->value);
        if (! empty($creditAppData)) {
            $coBuyerData = $this->mergeCreditAppData($coBuyerData, $creditAppData);
        }

        return $coBuyerData;
    }

    protected function mergeCreditAppData(array $coBuyerData, array $creditAppData): array
    {
        if (! empty($creditAppData['currentResidenceHistory'])) {
            $residence = $creditAppData['currentResidenceHistory'];
            $coBuyerData['currentResidence'] = [
                'address' => [
                    'line1' => $residence['addressLine'] ?? null,
                    'city' => $residence['city'] ?? null,
                    'stateOrProvince' => $residence['state'] ?? null,
                    'zipOrPostalCode' => $residence['zip'] ?? null,
                    'county' => $residence['county'] ?? null,
                ],
                'payment' => isset($residence['mortgagePayment']) ? (string) $residence['mortgagePayment'] : null,
                'durationInMonths' => $residence['monthsInResidence'] ?? 0,
                'status' => $residence['housingStatus'] ?? 'Undefined',
            ];
        }

        if (! empty($creditAppData['previousResidenceHistory'])) {
            $residence = $creditAppData['previousResidenceHistory'];
            $coBuyerData['previousResidence'] = [
                'address' => [
                    'line1' => $residence['addressLine'] ?? null,
                    'city' => $residence['city'] ?? null,
                    'stateOrProvince' => $residence['state'] ?? null,
                    'zipOrPostalCode' => $residence['zip'] ?? null,
                    'county' => $residence['county'] ?? null,
                ],
                'durationInMonths' => $residence['monthsInResidence'] ?? 0,
                'status' => $residence['housingStatus'] ?? 'Undefined',
            ];
        }

        if (! empty($creditAppData['currentEmploymentHistory'])) {
            $employment = $creditAppData['currentEmploymentHistory'];
            $coBuyerData['currentEmployment'] = [
                'name' => $employment['name'] ?? null,
                'phone' => $employment['phoneNumber'] ?? null,
                'address' => [
                    'line1' => $employment['addressLine1'] ?? null,
                    'line2' => $employment['addressLine2'] ?? null,
                    'city' => $employment['city'] ?? null,
                    'stateOrProvince' => $employment['state'] ?? null,
                    'zipOrPostalCode' => $employment['zip'] ?? null,
                ],
                'title' => $employment['occupation'] ?? null,
                'durationInMonths' => $employment['monthsOfEmployment'] ?? 0,
                'salary' => $employment['salary'] ?? 0,
                'salaryType' => $employment['salaryType'] ?? 'Monthly',
                'status' => $employment['status'] ?? 'Employed',
            ];
        }

        if (! empty($creditAppData['previousEmploymentHistory'])) {
            $employment = $creditAppData['previousEmploymentHistory'];
            $coBuyerData['previousEmployment'] = [
                'name' => $employment['name'] ?? null,
                'phone' => $employment['phoneNumber'] ?? null,
                'address' => [
                    'line1' => $employment['addressLine1'] ?? null,
                    'line2' => $employment['addressLine2'] ?? null,
                    'city' => $employment['city'] ?? null,
                    'stateOrProvince' => $employment['state'] ?? null,
                    'zipOrPostalCode' => $employment['zip'] ?? null,
                ],
                'title' => $employment['occupation'] ?? null,
                'durationInMonths' => $employment['monthsOfEmployment'] ?? 0,
                'salary' => $employment['salary'] ?? 0,
                'salaryType' => $employment['salaryType'] ?? 'Monthly',
                'status' => $employment['status'] ?? 'Employed',
            ];
        }

        if (! empty($creditAppData['insurance'])) {
            $insurance = $creditAppData['insurance'];
            $coBuyerData['otherFinancial'] = [
                'otherIncomeSource' => $insurance['otherIncomeSource'] ?? null,
                'otherIncomeAmount' => isset($insurance['otherIncomeAmount']) ? (string) $insurance['otherIncomeAmount'] : null,
                'bankName' => $insurance['bank'] ?? null,
                'bankContact' => $insurance['bankContact'] ?? null,
            ];
        }

        return $coBuyerData;
    }

    protected function saveCoBuyerIdentifier(array $response, People $coBuyerPeople): void
    {
        $customers = $response['customers'] ?? [];

        foreach ($customers as $customer) {
            $isPrimary = $customer['isPrimaryBuyer'] ?? true;
            if (! $isPrimary) {
                $coBuyerCrmId = $this->extractIdentifier($customer['identifiers'] ?? [], 'CrmId');
                if ($coBuyerCrmId) {
                    $coBuyerPeople->set(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value, $coBuyerCrmId);
                }

                break;
            }
        }
    }

    protected function extractIdentifier(array $identifiers, string $type): ?string
    {
        foreach ($identifiers as $identifier) {
            if (($identifier['type'] ?? '') === $type) {
                return $identifier['value'] ?? null;
            }
        }

        return null;
    }
}
