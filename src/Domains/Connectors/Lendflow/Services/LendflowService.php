<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Connectors\Lendflow\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;

class LendflowService
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function getWorkflowTemplateId(): string
    {
        $templateId = (string) ($this->company->get(ConfigurationEnum::WORKFLOW_TEMPLATE_ID->value) ?? '');

        if ($templateId === '') {
            throw new ValidationException('Lendflow workflow_template_id is not configured for company ' . (string) $this->company->getId());
        }

        return $templateId;
    }

    /**
     * Build the Lendflow application payload from a Deal.
     */
    public function buildApplicationPayload(Deal $deal): array
    {
        $primaryPhone = $this->resolvePrimaryPhone($deal);

        $payload = [
            'workflow_template_id' => $this->getWorkflowTemplateId(),
            'prequal' => $this->buildPrequal($deal),
            'business' => $this->buildBusiness($deal, $primaryPhone),
            'funding' => $this->buildFunding($deal),
        ];

        if ($deal->people_id !== null && $deal->people_id > 0) {
            $payload['personal'] = $this->buildPersonal($deal->people);
        }

        return $payload;
    }

    protected function resolvePrimaryPhone(Deal $deal): ?string
    {
        if ($deal->people_id === null || $deal->people_id <= 0 || $deal->people === null) {
            return null;
        }

        foreach ($deal->people->getAllPhones() as $phone) {
            $value = trim((string) $phone->value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function buildPrequal(Deal $deal): array
    {
        return array_filter([
            'prequal_requested_amount' => $this->toStr($deal->get('funding_amount_requested') ?? $deal->get('amount_requested')),
        ]);
    }

    protected function buildBusiness(Deal $deal, ?string $primaryPhone = null): array
    {
        $address = [
            'address_line' => $this->toStr($deal->get('business_address_line')),
            'city' => $this->toStr($deal->get('business_city')),
            'state' => $this->toStr($deal->get('business_state')),
            'country' => $this->toStr($deal->get('business_country') ?? 'US'),
            'zip' => $this->toStr($deal->get('business_zip')),
            'is_primary' => true,
        ];

        $business = array_filter([
            'business_legal_name' => $this->toStr($deal->get('business_legal_name')),
            'business_dba' => $this->toStr($deal->get('business_dba')),
            'business_stated_monthly_revenue' => $this->toStr($deal->get('business_stated_monthly_revenue') ?? $deal->get('monthly_business_revenue')),
            'business_ein' => Str::digitsOnly($this->toStr($deal->get('business_ein') ?? $deal->get('ein'))),
            'business_entity_type' => $this->toInt($deal->get('business_entity_type')),
            'business_start_date' => $this->toStr($deal->get('business_start_date')),
        ], static fn ($v) => $v !== '' && $v !== null);

        $addressFilled = false;
        foreach (['address_line', 'city', 'state', 'zip'] as $addressField) {
            if ($address[$addressField] !== '') {
                $addressFilled = true;

                break;
            }
        }
        if ($addressFilled) {
            $business['business_addresses'] = [$address];
        }

        if ($primaryPhone !== null && $primaryPhone !== '') {
            $business['business_phone_numbers'] = [[
                'phone_number' => $primaryPhone,
                'is_primary' => true,
                'line_type' => 'mobile',
            ]];
        }

        return $business;
    }

    protected function buildFunding(Deal $deal): array
    {
        return array_filter([
            'funding_estimated_annual_revenue' => $this->toStr($deal->get('funding_estimated_annual_revenue') ?? $deal->get('annual_revenue')),
            'funding_actual_average_monthly_revenue' => $this->toStr($deal->get('funding_actual_average_monthly_revenue') ?? $deal->get('monthly_business_revenue')),
            'funding_amount_requested' => $this->toStr($deal->get('funding_amount_requested') ?? $deal->get('amount_requested')),
            'funding_use_of_funds' => $this->toInt($deal->get('funding_use_of_funds') ?? 1),
        ], static fn ($v) => $v !== '' && $v !== null);
    }

    protected function buildPersonal(?People $people): array
    {
        if ($people === null) {
            return [];
        }

        $phones = [];
        foreach ($people->getAllPhones() as $phone) {
            $phones[] = [
                'phone_number' => (string) $phone->value,
                'is_primary' => true,
                'line_type' => 'mobile',
            ];
        }

        $emails = [];
        foreach ($people->getEmails() as $email) {
            $emails[] = (string) $email->value;
        }
        $primaryEmail = $emails[0] ?? '';
        $additionalEmails = array_slice($emails, 1);

        $addresses = [];
        $i = 0;
        foreach ($people->address()->get() as $a) {
            $addresses[] = [
                'address_line' => (string) ($a->address ?? ''),
                'city' => (string) ($a->city ?? ''),
                'state' => (string) ($a->state ?? ''),
                'country' => (string) ($a->country_code ?? 'US'),
                'zip' => (string) ($a->zip ?? ''),
                'is_primary' => $i === 0,
            ];
            $i++;
        }

        $personal = [
            'personal_first_name' => (string) $people->firstname,
            'personal_last_name' => (string) $people->lastname,
            'personal_is_primary' => true,
        ];

        $dob = $this->toStr($people->get('date_of_birth') ?? $people->dob ?? null);
        if ($dob !== '') {
            $personal['personal_date_of_birth'] = $dob;
        }

        $ssn = Str::digitsOnly($this->toStr($people->get('ssn')));
        if ($ssn !== '') {
            $personal['personal_ssn_itin'] = ['ssn' => $ssn];
        }

        if ($phones !== []) {
            $personal['personal_telephone'] = ['telephone' => $phones[0]['phone_number']];
            $personal['personal_phone_numbers'] = $phones;
        }

        if ($primaryEmail !== '') {
            $personal['personal_email_address'] = [
                'email_address' => $primaryEmail,
                'additional_emails' => $additionalEmails,
            ];
        }

        if ($addresses !== []) {
            $personal['personal_addresses'] = $addresses;
        }

        return [$personal];
    }

    protected function toStr(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    protected function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
