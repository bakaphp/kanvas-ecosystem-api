<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use DateTime;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

class AddCreditAppAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $message, bool $coBuyer = false, bool $sendEmail = true): array
    {
        $formData = $message['data']['form'];
        //$durationAtAddress = explode('.', $formData['housing']['time_at_address']);
        //$durationAtJob = explode('.', $formData['financial']['years_at_current_employment']);
        //$durationAtPreviousJob = explode('.', $formData['financial']['years_at_previous_employment']);

        //how is even possible? for now add fix
        $contactDOB = ! empty($formData['personal']['dob']) ? new DateTime($formData['personal']['dob']) : null;
        //$employContact = $formData['financial']['current_employer_phone'];
        //$previousEmployerStateId = $formData['financial']['state']['id'] ?? 0;
        //$previousEmployersState = States::findFirst($previousEmployerStateId);
        //$currentEmployerStateId = $formData['financial']['state']['id'] ?? 0;
        $currentState = $formData['housing']['state']['code'] ?? 'GA';
        //$currentEmployerState = States::findFirst($currentEmployerStateId);
        //$currentEmployerPhoneNumber = ! empty($formData['financial']['current_employer_phone']) && Phone::removeUSCountryCode($formData['financial']['current_employer_phone']) === 10 ? Phone::removeUSCountryCode($formData['financial']['current_employer_phone']) : '';
        //$previousEmployerPhoneNumber = ! empty($formData['financial']['previous_employer_phone']) && Phone::removeUSCountryCode($formData['financial']['previous_employer_phone']) === 10 ? Phone::removeUSCountryCode($formData['financial']['previous_employer_phone']) : '';
        $currentMaritalStatus = isset($formData['personal']['marital_status']) && $formData['personal']['marital_status'] !== null ? strtolower($formData['personal']['marital_status']) : null;

        $eLeadStates = [
            'AA' => 72,
            'AE' => 73,
            'AK' => 2,
            'AL' => 1,
            'AP' => 74,
            'AR' => 4,
            'AZ' => 3,
            'CA' => 5,
            'CO' => 6,
            'CT' => 7,
            'DC' => 51,
            'DE' => 8,
            'FL' => 9,
            'GA' => 10,
            'GU' => 75,
            'HI' => 11,
            'IA' => 15,
            'ID' => 12,
            'IL' => 13,
            'IN' => 14,
            'KS' => 16,
            'KY' => 17,
            'LA' => 18,
            'MA' => 21,
            'MD' => 20,
            'ME' => 19,
            'MI' => 22,
            'MN' => 23,
            'MO' => 25,
            'MS' => 24,
            'MT' => 26,
            'NC' => 33,
            'ND' => 34,
            'NE' => 27,
            'NH' => 29,
            'NJ' => 30,
            'NM' => 31,
            'NV' => 28,
            'NY' => 32,
            'OH' => 35,
            'OK' => 36,
            'OR' => 37,
            'PA' => 38,
            'PR' => 52,
            'RI' => 39,
            'SC' => 40,
            'SD' => 41,
            'TN' => 42,
            'TX' => 43,
            'UT' => 44,
            'VA' => 46,
            'VI' => 53,
            'VT' => 45,
            'WA' => 47,
            'WI' => 49,
            'WV' => 48,
            'WY' => 50,
        ];

        $eLeadHousingType = [
            'own' => 682,
            'rent' => 683,
            'family' => 4389,
            'relative' => 4389,
            'military' => 4390,
            'owns mobile home' => 4391,
            'buying mobile home' => 4392,
            'mortgage' => 4393,
            'other' => 4394,
        ];

        $eLeadEmploymentStatus = [
            'full time' => '4396',
            'part time' => '4397',
            'retired' => '4398',
            'active military' => '4399',
            'retired military' => '4400',
            'self employed' => '4401',
            'contract' => '4402',
            'seasonal' => '4403',
            'temporary' => '4404',
            'student full time' => '4405',
            'student part time' => '4406',
        ];

        $maritalStatus = [
            'single' => '37',
            'married' => '39',
            'divorced' => '41',
            'widowed' => '42',
        ];

        $postData = [
            'birthdayMonth' => is_object($contactDOB) ? $contactDOB->format('m') : '',
            'birthdayYear' => is_object($contactDOB) ? $contactDOB->format('Y') : '',
            'birthday' => is_object($contactDOB) ? $contactDOB->format('d') : '',
            'ssn' => $formData['personal']['ssn'],
            'homePhone' => ! empty($formData['personal']['home_number']) ? $formData['personal']['home_number'] : $formData['personal']['mobile_number'],
            'cellPhone' => $formData['personal']['mobile_number'],
            'street' => $formData['housing']['address'] ?? '',
            'street2' => $formData['housing']['address_line2'] ?? '',
            'city' => $formData['housing']['city']['name'] ?? ($formData['housing']['city'] ?? ''),
            'state' => (string) $eLeadStates[$currentState] ?? '10',
            'zip' => $formData['housing']['zip_code'] ?? '',
            'email' => $formData['personal']['email'] ?? '',

            'housingType' => $formData['housing']['residence_type'] ? (string) $eLeadHousingType[strtolower($formData['housing']['residence_type'])] : '682',
            'housingExpenses' => $formData['housing']['rent'] ?? '',

            'howLongYear' => isset($formData['housing']['time_at_address']) && ! empty($formData['housing']['time_at_address']) ? (string) $formData['housing']['time_at_address'] : '0',
            'howLongMonth' => isset($formData['housing']['time_at_address']) && ! empty($formData['housing']['time_at_address']) ? (string) ($formData['housing']['time_at_address'] * 12) : '0',
            'previousAddressStreet' => $formData['housing']['previous_address'] ?? '',
            'previousAddressStreet2' => $formData['housing']['previous_address_line2'] ?? '',
            'previousAddressCity' => $formData['housing']['previous_city']['name'] ?? ($formData['housing']['previous_city'] ?? ''),
            'previousAddressState' => isset($formData['housing']['previous_state']['code']) && ! empty($formData['housing']['previous_state']['code']) && isset($eLeadStates[$formData['housing']['previous_state']['code']]) ? (string) $eLeadStates[$formData['housing']['previous_state']['code']] : '',
            'previousAddressZipCode' => $formData['housing']['previous_zip_code'] ?? '',
            'previousTimeAtAddress' => isset($formData['housing']['previous_time_at_address']) && ! empty($formData['housing']['previous_time_at_address']) ? (string) $formData['housing']['previous_time_at_address'] : '0',
            'currentEmploymentStatusType' => isset($formData['financial']['employment_status']) && ! empty($formData['financial']['employment_status']) ? (string) $eLeadEmploymentStatus[strtolower($formData['financial']['employment_status'])] : '4396',
            'currentEmployerJobTitle' => isset($formData['financial']['current_employment_title']) && ! empty($formData['financial']['current_employment_title']) ? substr($formData['financial']['current_employment_title'], 0, 54) : '',
            'currentEmployerName' => isset($formData['financial']['current_employer']) && ! empty($formData['financial']['current_employer']) ? substr($formData['financial']['current_employer'], 0, 54) : '',
            'currentEmployerPhone' => (string) $formData['financial']['current_employer_phone'],
            'currentEmployerHowLongYear' => isset($formData['financial']['years_at_current_employment']) && ! empty($formData['financial']['years_at_current_employment']) ? (string) $formData['financial']['years_at_current_employment'] : '0',
            'currentEmployerHowLongMonth' => isset($formData['financial']['years_at_current_employment']) && ! empty($formData['financial']['years_at_current_employment']) ? (string) ($formData['financial']['years_at_current_employment'] * 12) : '0',
            'currentEmployerAddressStreet' => $formData['financial']['current_employer_address_line1'] ?? '',
            'currentEmployerAddressStreet2' => $formData['financial']['current_employer_address_line2'] ?? '',
            'currentEmployerAddressCity' => $formData['financial']['city']['name'] ?? '',
            'currentEmployerAddressState' => isset($formData['financial']['state']['code']) && ! empty($formData['financial']['state']['code']) && isset($eLeadStates[$formData['financial']['state']['code']]) ? (string) $eLeadStates[$formData['financial']['state']['code']] : '',
            'currentEmployerAddressZipCode' => $formData['financial']['zip_code'] ?? '',

            'previousEmployerPhone' => (string) $formData['financial']['previous_employer_phone'],
            'previousEmployerName' => isset($formData['financial']['previous_employer']) && ! empty($formData['financial']['previous_employer']) ? substr($formData['financial']['previous_employer'], 0, 54) : '',
            'previousEmployerHowLongYear' => isset($formData['financial']['years_at_previous_employment']) && ! empty($formData['financial']['years_at_previous_employment']) ? (string) $formData['financial']['years_at_previous_employment'] : '',
            'previousEmployerHowLongMonth' => isset($formData['financial']['years_at_previous_employment']) && ! empty($formData['financial']['years_at_previous_employment']) ? (string) ($formData['financial']['years_at_previous_employment'] * 12) : '',
            'previousEmployerAddressStreet' => $formData['financial']['previous_employer_address_line1'] ?? '',
            'previousEmployerAddressStreet2' => $formData['financial']['previous_employer_address_line2'] ?? '',
            'previousEmployerAddressCity' => $formData['financial']['previous_city']['name'] ?? '',
            'previousEmployerAddressState' => isset($formData['financial']['previous_state']['code']) && ! empty($formData['financial']['previous_state']['code']) && isset($eLeadStates[$formData['financial']['previous_state']['code']]) ? (string) $eLeadStates[$formData['financial']['previous_state']['code']] : '',
            'previousEmployerAddressZipCode' => $formData['financial']['previous_zip_code'] ?? '',

            'firstname' => $formData['personal']['first_name'] ?? '',
            'lastname' => $formData['personal']['last_name'] ?? '',
            'middleName' => $formData['personal']['middle_name'] ?? '',
            'grossIncome' => (string) $formData['financial']['gross_income'] ?? '',
            'otherMonthlyIncome' => (string) $formData['financial']['other_income'] ?? '',
            'otherMonthlyIncomeSource' => ! empty($formData['financial']['other_income_source']) ? (string) $formData['financial']['other_income_source'] : '',
            'mortgageAmount' => (string) $formData['housing']['rent'] ?? '',
            'maritalStatus' => ! empty($currentMaritalStatus) && isset($maritalStatus[$currentMaritalStatus]) ? (string) $maritalStatus[$currentMaritalStatus] : '',
        ];

        // do post to https://sa-image-preview.vercel.app/api/e-leads-add-credit-app?lDID=73405131&lPID=64560098&co_buyer=false
        if (! empty($postData['previousEmployerAddressCity'])
            && ! empty($postData['previousEmployerAddressState'])
            && empty($postData['previousEmployerAddressZipCode'])) {
            $postData['previousEmployerAddressZipCode'] = '00000';
        }

        if (! empty($postData['currentEmployerAddressCity'])
            && ! empty($postData['currentEmployerAddressState'])
            && empty($postData['currentEmployerAddressZipCode'])) {
            $postData['currentEmployerAddressZipCode'] = '00000';
        }

        if (isset($formData['financial']['employment_status']) && strtolower($formData['financial']['employment_status']) == 'retired') {
            $postData['previousEmployerPhone'] = $postData['currentEmployerPhone'];
            $postData['previousEmployerName'] = $postData['currentEmployerName'];
            $postData['previousEmployerHowLongYear'] = $postData['currentEmployerHowLongYear'];
            $postData['previousEmployerHowLongMonth'] = $postData['currentEmployerHowLongMonth'];
            $postData['previousEmployerAddressStreet'] = $postData['currentEmployerAddressStreet'];
            $postData['previousEmployerAddressStreet2'] = $postData['currentEmployerAddressStreet2'];
            $postData['previousEmployerAddressCity'] = $postData['currentEmployerAddressCity'];
            $postData['previousEmployerAddressState'] = $postData['currentEmployerAddressState'];
            $postData['previousEmployerAddressZipCode'] = $postData['currentEmployerAddressZipCode'];

            $postData['currentEmployerJobTitle'] = '';
            $postData['currentEmployerPhone'] = '';
            $postData['currentEmployerName'] = '';
            $postData['currentEmployerHowLongYear'] = '';
            $postData['currentEmployerHowLongMonth'] = '';
            $postData['currentEmployerAddressStreet'] = '';
            $postData['currentEmployerAddressStreet2'] = '';
            $postData['currentEmployerAddressCity'] = '';
            $postData['currentEmployerAddressState'] = '';
            $postData['currentEmployerAddressZipCode'] = '';
        }

        $coBuyer = $coBuyer ? 'true' : 'false';
        $companyId = $this->lead->company->get('integration_store_id') ?? 20260;
        $url = '/?lDID=' . $this->lead->get(CustomFieldEnum::LEAD_ID->value) . '&lPID=' . $this->lead->people->get(CustomFieldEnum::PERSON_ID->value) . '&co_buyer=' . $coBuyer . '&company=' . $companyId;

        return [
            'url' => $url,
            'data' => $postData,
        ];
    }
}
