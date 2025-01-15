<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Services;

use Baka\Contracts\AppInterface;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Exceptions\ValidationException;
use SimpleXMLElement;

class DataPushMultiMappingService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    public function pushData(EngagementMessage $message, string $mappingType = 'ADF'): SimpleXMLElement
    {
        // Extract form data from message
        $formData = $message->data['form'];

        // Build XML based on mapping type
        $xml = $this->generateXml($formData, $mappingType);

        /**
         * @todo send this to endpoint
         */

        return $xml;
    }

    protected function generateXml(array $formData, string $mappingType): SimpleXMLElement
    {
        $xml = new SimpleXMLElement('<Request/>');

        switch ($mappingType) {
            case 'ADF':
                $this->mapAdf($xml, $formData);

                break;
            case 'RouteOne':
                $this->mapRouteOne($xml, $formData);

                break;
            case 'AppOne':
                $this->mapAppOne($xml, $formData);

                break;
            case 'DealerTrack':
                $this->mapDealerTrack($xml, $formData);

                break;
            default:
                throw new ValidationException('Unknown mapping type: ' . $mappingType);
        }

        return $xml;
    }

    protected function mapAdf(SimpleXMLElement $xml, array $data): void
    {
        $prospect = $xml->addChild('Prospect');

        // Customer Information
        $customer = $prospect->addChild('Customer');

        // Name Information
        $contact = $customer->addChild('Contact');
        $contact->addAttribute('primarycontact', '1');
        $name = $contact->addChild('Name');
        $name->addChild('part', trim($data['personal']['first_name']))->addAttribute('part', 'first');
        $name->addChild('part', trim($data['personal']['middle_name'] ?? ''))->addAttribute('part', 'middle');
        $name->addChild('part', trim($data['personal']['last_name']))->addAttribute('part', 'last');
        $customer->addChild('Suffix', ''); // Currently Not Supported

        // Personal Information
        $contact->addChild('DOB', date('Y-m-d', strtotime($data['personal']['dob'])));
        $contact->addChild('Email', $data['personal']['email']);
        $phone1 = $contact->addChild('Phone', $data['personal']['home_number'] ?? '');
        $phone1->addAttribute('type', 'phone');
        $phone1->addAttribute('time', 'morning');
        $phone2 = $contact->addChild('Phone', $data['personal']['mobile_number'] ?? '');
        $phone2->addAttribute('type', 'phone');
        $phone2->addAttribute('time', 'evening');

        // Current Residence
        $address = $customer->addChild('Address');
        $address->addChild('Street', $data['housing']['address'])->addAttribute('line', '1');
        $address->addChild('City', $data['housing']['city']);
        $address->addChild('State', $data['housing']['state']['code']);
        $address->addChild('Zip', $data['housing']['zip_code']);

        // Previous Residence if available
        if (! empty($data['housing']['previous_address'])) {
            $previousResidence = $customer->addChild('Residence');
            $previousResidence->addAttribute('type', 'Previous');
            $previousResidence->addChild('Residence1')->addChild('Address', $data['housing']['previous_address']);
            $previousResidence->addChild('City', $data['housing']['previous_city'] ?? '');
            $previousResidence->addChild('State', $data['housing']['previous_state'] ?? '');
            $previousResidence->addChild('Zip', $data['housing']['previous_zip_code'] ?? '');
            if (! empty($data['housing']['previous_time_at_address'])) {
                $previousResidence->addChild('Period', (string)$data['housing']['previous_time_at_address']);
            }
        }

        // Current Employment
        $currentEmployment = $customer->addChild('Employment');
        $currentEmployment->addAttribute('type', 'current');
        $currentEmployment->addChild('EmployerName', $data['financial']['current_employer'] ?? '');
        $currentEmployment->addChild('Occupation', $data['financial']['current_employment_title'] ?? '');
        $currentEmployment->addChild('Period', $data['financial']['years_at_current_employment'] ?? '');
        $currentEmployment->addChild('Phone', $data['financial']['current_employer_phone'] ?? '')->addAttribute('type', 'phone');

        // Income Information
        $monthlyIncome = floatval($data['financial']['gross_income']) / 12;
        $currentEmployment->addChild('MonthlySalary', number_format($monthlyIncome, 2, '.', ''));
        if (! empty($data['financial']['other_income'])) {
            $currentEmployment->addChild('OtherIncome', (string)$data['financial']['other_income']);
        }

        // Previous Employment if available
        if (! empty($data['financial']['previous_employer'])) {
            $previousEmployment = $customer->addChild('Employment');
            $previousEmployment->addAttribute('type', 'Previous');
            $previousEmployment->addChild('EmployerName', $data['financial']['previous_employer']);
            if (! empty($data['financial']['years_at_previous_employment'])) {
                $previousEmployment->addChild('Period', (string) $data['financial']['years_at_previous_employment']);
            }
            if (! empty($data['financial']['previous_income'])) {
                $previousEmployment->addChild('MonthlySalary', (string)$data['financial']['previous_income']);
            }
        }

        // Vehicle of Interest
        if (! empty($data['vehicle']['interest'])) {
            $vehicleInterest = $customer->addChild('Vehicle');
            $vehicleInterest->addAttribute('interest', 'buy');
            $vehicleInterest->addAttribute('status', 'new');
            $vehicleInterest->addChild('Year', $data['vehicle']['year'] ?? '');
            $vehicleInterest->addChild('Make', $data['vehicle']['make'] ?? '');
            $vehicleInterest->addChild('Model', $data['vehicle']['model'] ?? '');
            $vehicleInterest->addChild('VIN', $data['vehicle']['vin'] ?? '');
            $vehicleInterest->addChild('Stock', $data['vehicle']['stock'] ?? '');
        }

        // Vehicle Finance
        if (! empty($data['vehicle']['finance'])) {
            $vehicleFinance = $customer->addChild('VehicleFinance');
            $vehicleFinance->addChild('DesiredDownPayment', $data['vehicle']['finance']['down_payment'] ?? '');
            $vehicleFinance->addChild('DesiredMonthlyPayment', $data['vehicle']['finance']['monthly_payment'] ?? '');
        }

        // Trade-In Vehicle
        if (! empty($data['vehicle']['trade_in'])) {
            $tradeInVehicle = $customer->addChild('Vehicle');
            $tradeInVehicle->addAttribute('interest', 'trade-in');
            $tradeInVehicle->addAttribute('status', 'used');
            $tradeInVehicle->addChild('Year', $data['vehicle']['trade_in']['year'] ?? '');
            $tradeInVehicle->addChild('Make', $data['vehicle']['trade_in']['make'] ?? '');
            $tradeInVehicle->addChild('Model', $data['vehicle']['trade_in']['model'] ?? '');
            $tradeInVehicle->addChild('Mileage', $data['vehicle']['trade_in']['mileage'] ?? '');
        }

        // Company Information
        if (! empty($data['business']['own_company'])) {
            $prospect->addChild('Company')->addChild('OwnCompany', $data['business']['own_company']);
        }

        // Reference Information
        if (! empty($data['reference'])) {
            $reference = $customer->addChild('Reference');
            $reference->addChild('ReferenceName', $data['reference']['name'] ?? '');
            $reference->addChild('ReferencePhoneNumber', $data['reference']['phone'] ?? '');
            $referenceAddress = $reference->addChild('ReferenceAddress');
            $referenceAddress->addChild('Street', $data['reference']['address'] ?? '')->addAttribute('line', '1');
            $referenceAddress->addChild('Zip', $data['reference']['zip'] ?? '');
            $referenceAddress->addChild('City', $data['reference']['city'] ?? '');
            $referenceAddress->addChild('State', $data['reference']['state'] ?? '');
        }

        // Checking Account Information
        if (! empty($data['financial']['checking_account'])) {
            $customer->addChild('CheckingAccount')->addChild('HaveCheckingAccount', $data['financial']['checking_account']);
        }
    }

    protected function mapRouteOne(SimpleXMLElement $xml, array $data): void
    {
        $routeOne = $xml->addChild('RouteOneLead');

        // Personal Information
        $personName = $routeOne->addChild('PersonName');
        $personName->addChild('GivenName', trim($data['personal']['first_name']));
        $personName->addChild('MiddleName', trim($data['personal']['middle_name'] ?? ''));
        $personName->addChild('FamilyName', trim($data['personal']['last_name']));
        $personName->addChild('Suffix', ''); // Currently Not Supported

        $alternatePartyIds = $routeOne->addChild('AlternatePartyIds');
        $alternatePartyIds->addChild('Id', $data['personal']['ssn']);

        $demographics = $routeOne->addChild('Demographics');
        $demographics->addChild('BirthDate', date('Y-m-d', strtotime($data['personal']['dob'])));

        // Contact Information
        $contact = $routeOne->addChild('Contact');
        $contact->addChild('EmailAddress', $data['personal']['email'])->addAttribute('desc', 'Home');
        $telephone1 = $contact->addChild('Telephone', $data['personal']['home_number'] ?? '');
        $telephone1->addAttribute('desc', 'EveningPhone');
        $telephone2 = $contact->addChild('Telephone', $data['personal']['mobile_number'] ?? '');
        $telephone2->addAttribute('desc', 'Other');

        // Current Address
        $currentAddress = $routeOne->addChild('Address');
        $currentAddress->addAttribute('qualifier', 'HomeAddress');
        $currentAddress->addChild('AddressLine', $data['housing']['address']);
        $currentAddress->addChild('City', $data['housing']['city']);
        $currentAddress->addChild('StateOrProvince', $data['housing']['state']['code']);
        $currentAddress->addChild('PostalCode', $data['housing']['zip_code']);
        $currentAddress->addChild('PeriodOfResidence', (string)floor(floatval($data['housing']['time_at_address'])))
            ->addAttribute('Period', 'MO');

        // Previous Address if available
        if (! empty($data['housing']['previous_address'])) {
            $previousAddress = $routeOne->addChild('Address');
            $previousAddress->addAttribute('qualifier', 'PreviousAddress');
            $previousAddress->addChild('AddressLine', $data['housing']['previous_address']);
            $previousAddress->addChild('City', $data['housing']['previous_city'] ?? '');
            $previousAddress->addChild('StateOrProvince', $data['housing']['previous_state'] ?? '');
            $previousAddress->addChild('PostalCode', $data['housing']['previous_zip_code'] ?? '');
            if (! empty($data['housing']['previous_time_at_address'])) {
                $previousAddress->addChild('PeriodOfResidence', (string)floor(floatval($data['housing']['previous_time_at_address'])))
                    ->addAttribute('Period', 'MO');
            }
        }

        // Current Employment
        $employment = $routeOne->addChild('Employer');
        $employment->addChild('Name', $data['financial']['current_employer'] ?? '');
        $routeOne->addChild('Occupation', $data['financial']['current_employment_title'] ?? '');
        $periodOfEmployment = $routeOne->addChild('PeriodOfEmployment', (string)floor(floatval($data['financial']['years_at_current_employment'])));
        $periodOfEmployment->addAttribute('period', 'MO');
        $employerContact = $employment->addChild('Contact');
        $employerTelephone = $employerContact->addChild('Telephone', $data['financial']['current_employer_phone'] ?? '');
        $employerTelephone->addAttribute('desc', 'Day phone');

        // Income Information
        $monthlyIncome = floatval($data['financial']['gross_income']) / 12;
        $income = $routeOne->addChild('Income', (string) number_format($monthlyIncome, 2, '.', ''));
        $income->addAttribute('currency', 'USD');
        $income->addAttribute('period', 'MO');

        // Other Income
        if (! empty($data['financial']['other_income'])) {
            $otherIncome = $routeOne->addChild('OtherIncome');
            $otherIncomeAmount = $otherIncome->addChild('OtherIncomeAmount', (string)$data['financial']['other_income']);
            $otherIncomeAmount->addAttribute('currency', 'USD');
            $otherIncomeAmount->addAttribute('period', 'MO');
            $otherIncome->addChild('IncomeSource', $data['financial']['other_income_source'] ?? '');
        }
    }

    protected function mapAppOne(SimpleXMLElement $xml, array $data): void
    {
        $appOne = $xml->addChild('AppOneLead');
        $borrowers = $appOne->addChild('borrowers');
        $borrower = $borrowers->addChild('borrower');

        // Personal Information
        $borrower->addChild('FirstName', trim($data['personal']['first_name']));
        $borrower->addChild('middlename', trim($data['personal']['middle_name'] ?? ''));
        $borrower->addChild('lastname', trim($data['personal']['last_name']));
        $borrower->addChild('ssn', $data['personal']['ssn']);
        $borrower->addChild('dob', date('Y-m-d', strtotime($data['personal']['dob'])));
        $borrower->addChild('email', $data['personal']['email']);

        // Contact Information
        if (! empty($data['personal']['home_number'])) {
            $borrower->addChild('homephone', $data['personal']['home_number']);
        }
        if (! empty($data['personal']['mobile_number'])) {
            $borrower->addChild('mobilephone', $data['personal']['mobile_number']);
        }

        // Current Address
        $addresses = $borrower->addChild('addresses');
        $currentAddress = $addresses->addChild('address');
        $currentAddress->addChild('isCurrent', 'true');
        $currentAddress->addChild('address', $data['housing']['address']);
        $currentAddress->addChild('city', $data['housing']['city']);
        $currentAddress->addChild('state', $data['housing']['state']['code']);
        $currentAddress->addChild('zip', $data['housing']['zip_code']);
        $currentAddress->addChild('howlongyears', (string)floor(floatval($data['housing']['time_at_address'])));
        $currentAddress->addChild('howlongmonths', (string)floor((floatval($data['housing']['time_at_address']) * 12) % 12));

        // Previous Address if available
        if (! empty($data['housing']['previous_address'])) {
            $previousAddress = $addresses->addChild('address');
            $previousAddress->addChild('isCurrent', 'false');
            $previousAddress->addChild('address', $data['housing']['previous_address']);
            $previousAddress->addChild('city', $data['housing']['previous_city'] ?? '');
            $previousAddress->addChild('state', $data['housing']['previous_state'] ?? '');
            $previousAddress->addChild('zip', $data['housing']['previous_zip_code'] ?? '');
            if (! empty($data['housing']['previous_time_at_address'])) {
                $previousAddress->addChild('howlongyears', (string)floor(floatval($data['housing']['previous_time_at_address'])));
                $previousAddress->addChild('howlongmonths', (string)floor((floatval($data['housing']['previous_time_at_address']) * 12) % 12));
            }
        }

        // Residence Information
        $borrower->addChild('status', $data['housing']['residence_type'] ?? '');
        $borrower->addChild('monthlypayment', (string)$data['housing']['rent'] ?? '');

        // Current Employment
        $employmentInfo = $borrower->addChild('EmploymentInfo');
        $currentEmployment = $employmentInfo->addChild('Employment');
        $currentEmployment->addChild('isCurrent', 'true');
        $currentEmployment->addChild('employerName', $data['financial']['current_employer'] ?? '');
        $currentEmployment->addChild('status', $data['financial']['employment_status'] ?? '');
        $currentEmployment->addChild('occupation', $data['financial']['current_employment_title'] ?? '');
        $currentEmployment->addChild('howlongyears', (string)floor(floatval($data['financial']['years_at_current_employment'])));
        $currentEmployment->addChild('howlongmonths', (string)floor((floatval($data['financial']['years_at_current_employment']) * 12) % 12));
        $currentEmployment->addChild('workphone', $data['financial']['current_employer_phone'] ?? '');

        // Income Information
        $monthlyIncome = floatval($data['financial']['gross_income']) / 12;
        $grossSalary = $currentEmployment->addChild('grosssalary');
        $grossSalary->addChild('grosssalarytype', 'Monthly');
        $grossSalary->addChild('amount', number_format($monthlyIncome, 2, '.', ''));

        // Other Income
        if (! empty($data['financial']['other_income'])) {
            $otherIncome = $currentEmployment->addChild('otherincome');
            $otherIncome->addChild('source', $data['financial']['other_income_source'] ?? '');
            $otherIncome->addChild('amount', (string)$data['financial']['other_income']);
            $otherIncome->addChild('amounttype', 'Monthly');
        }

        // Previous Employment if available
        if (! empty($data['financial']['previous_employer'])) {
            $previousEmployment = $employmentInfo->addChild('Employment');
            $previousEmployment->addChild('isCurrent', 'false');
            $previousEmployment->addChild('employerName', $data['financial']['previous_employer']);
            if (! empty($data['financial']['years_at_previous_employment'])) {
                $previousEmployment->addChild('howlongyears', (string)floor(floatval($data['financial']['years_at_previous_employment'])));
                $previousEmployment->addChild('howlongmonths', (string)floor((floatval($data['financial']['years_at_previous_employment']) * 12) % 12));
            }
            $previousEmployment->addChild('occupation', $data['financial']['previous_employment_title'] ?? '');
        }
    }

    protected function mapDealerTrack(SimpleXMLElement $xml, array $data): void
    {
        $dealerTrack = $xml->addChild('DealerTrack');

        // Primary Applicant
        $primaryApplicant = $dealerTrack->addChild('PrimaryApplicant');
        $primaryApplicant->addChild('FirstName', trim($data['personal']['first_name']));
        $primaryApplicant->addChild('MiddleInitial', mb_substr(trim($data['personal']['middle_name']), 0, 1) ?? '');
        $primaryApplicant->addChild('LastName', trim($data['personal']['last_name']));
        $primaryApplicant->addChild('Suffix', ''); // Currently Not Supported
        $primaryApplicant->addChild('SSN', $data['personal']['ssn']);
        $primaryApplicant->addChild('DateOfBirth', date('Y-m-d', strtotime($data['personal']['dob'])));
        $primaryApplicant->addChild('EmailAddress', $data['personal']['email']);
        $primaryApplicant->addChild('HomePhone', $data['personal']['home_number'] ?? '');
        $primaryApplicant->addChild('CellPhone', $data['personal']['mobile_number'] ?? '');

        // Current Address
        $currentAddress = $primaryApplicant->addChild('CurrentAddress');
        $currentAddress->addChild('AddressLine1', $data['housing']['address']);
        $currentAddress->addChild('AddressLine2', $data['housing']['address_line2'] ?? '');
        $currentAddress->addChild('City', $data['housing']['city']);
        $currentAddress->addChild('State', $data['housing']['state']['code']);
        $currentAddress->addChild('ZipCode', $data['housing']['zip_code']);
        $primaryApplicant->addChild('TotalMonthsAtAddress', (string)(floor(floatval($data['housing']['time_at_address'])) * 12 + floor((floatval($data['housing']['time_at_address']) * 12) % 12)));

        // Previous Address if available
        if (! empty($data['housing']['previous_address'])) {
            $primaryApplicant->addChild('PreviousAddressLine1', $data['housing']['previous_address']);
            $primaryApplicant->addChild('PreviousAddressLine2', $data['housing']['previous_address_line2'] ?? '');
            $primaryApplicant->addChild('PreviousCity', $data['housing']['previous_city'] ?? '');
            $primaryApplicant->addChild('PreviousState', $data['housing']['previous_state'] ?? '');
            $primaryApplicant->addChild('PreviousZipCode', $data['housing']['previous_zip_code'] ?? '');
            if (! empty($data['housing']['previous_time_at_address'])) {
                $primaryApplicant->addChild('TotalMonthsAtPreviousAddress', (string)(floor(floatval($data['housing']['previous_time_at_address'])) * 12 + floor((floatval($data['housing']['previous_time_at_address']) * 12) % 12)));
            }
        }

        // Residence Information
        $primaryApplicant->addChild('HousingStatus', $data['housing']['residence_type'] ?? '');
        $primaryApplicant->addChild('MortgageOrRent', (string)$data['housing']['rent'] ?? '');

        // Current Employment
        $primaryApplicant->addChild('EmployedBy', $data['financial']['current_employer'] ?? '');
        $primaryApplicant->addChild('EmploymentStatus', $data['financial']['employment_status'] ?? '');
        $primaryApplicant->addChild('Occupation', $data['financial']['current_employment_title'] ?? '');
        $primaryApplicant->addChild('TotalMonthsEmployed', (string)(floor(floatval($data['financial']['years_at_current_employment'])) * 12 + floor((floatval($data['financial']['years_at_current_employment']) * 12) % 12)));
        $primaryApplicant->addChild('BusinessPhone', $data['financial']['current_employer_phone'] ?? '');

        // Income Information
        $monthlyIncome = floatval($data['financial']['gross_income']) / 12;
        $primaryApplicant->addChild('MonthlyIncome', number_format($monthlyIncome, 2, '.', ''));

        // Other Income
        if (! empty($data['financial']['other_income'])) {
            $primaryApplicant->addChild('OtherMonthlyIncome', (string)$data['financial']['other_income']);
            $primaryApplicant->addChild('OtherIncomeSource', $data['financial']['other_income_source'] ?? '');
        }

        // Previous Employment if available
        if (! empty($data['financial']['previous_employer'])) {
            $primaryApplicant->addChild('PreviousEmployedBy', $data['financial']['previous_employer']);
            if (! empty($data['financial']['years_at_previous_employment'])) {
                $primaryApplicant->addChild('PreviousTotalMonthsEmployed', (string)(floor(floatval($data['financial']['years_at_previous_employment'])) * 12 + floor((floatval($data['financial']['years_at_previous_employment']) * 12) % 12)));
            }
            $primaryApplicant->addChild('PreviousOccupation', $data['financial']['previous_employment_title'] ?? '');
        }

        // Loan Type
        $dealerTrack->addChild('loan_type', '')->addAttribute('type', 'auto');
    }
}
