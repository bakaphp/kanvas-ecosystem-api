<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;

class ResolveInsuranceCustomerAction
{
    public function __construct(
        private readonly Lead $lead,
    ) {
    }

    public function execute(): InsuranceCustomer
    {
        return $this->storedOnLead()->fallbackTo($this->fromProfile());
    }

    private function storedOnLead(): InsuranceCustomer
    {
        $stored = [];

        foreach (InsuranceCustomerFieldEnum::cases() as $field) {
            $stored[$field->value] = $this->lead->get($field->customFieldKey());
        }

        return InsuranceCustomer::fromArray($stored);
    }

    private function fromProfile(): InsuranceCustomer
    {
        $people = $this->lead->people;

        return InsuranceCustomer::fromArray([
            InsuranceCustomerFieldEnum::FIRST_NAME->value => $people?->firstname ?: $this->lead->firstname,
            InsuranceCustomerFieldEnum::LAST_NAME->value => $people?->lastname ?: $this->lead->lastname,
            InsuranceCustomerFieldEnum::EMAIL->value => $people?->getEmails()->first()?->value ?: $this->lead->email,
            InsuranceCustomerFieldEnum::PHONE->value => $people?->getPhones()->first()?->value ?: $this->lead->phone,
            InsuranceCustomerFieldEnum::BIRTH_DATE->value => $people?->dob?->format('Y-m-d'),
        ]);
    }
}
