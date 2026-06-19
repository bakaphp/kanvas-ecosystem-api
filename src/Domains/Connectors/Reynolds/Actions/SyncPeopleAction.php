<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Kanvas\Connectors\Reynolds\DataTransferObject\Customer as CustomerData;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;

/**
 * Builds the People payload section used by PushLeadAction (Insert Sales Lead transaction).
 *
 * Reynolds has no standalone "create customer" endpoint — the customer is created
 * implicitly when a lead is inserted/updated. This action's job is therefore to
 * shape the People model into the IndividualCustomer/BusinessCustomer + Address +
 * PhoneNumbers + Email + Consent payload sections.
 */
class SyncPeopleAction
{
    public function __construct(
        protected People $people
    ) {
    }

    public function execute(): array
    {
        $customer = CustomerData::fromPeople($this->people);

        $payload = [];

        if ($customer->isBusiness) {
            $payload['BusinessCustomer'] = $customer->toBusinessCustomer();
        } else {
            $payload['IndividualCustomer'] = $customer->toIndividualCustomer();
        }

        $address = $customer->toAddress();
        if (! empty($address)) {
            $payload['Address'] = $address;
        }

        $phones = $customer->toPhoneNumbers();
        if (! empty($phones)) {
            $payload['PhoneNumbers'] = $phones;
        }

        $email = $customer->toEmail();
        if (! empty($email)) {
            $payload['Email'] = $email;
        }

        $payload['Consent'] = $customer->toConsent();

        return $payload;
    }

    public function getNameRecId(): ?string
    {
        $value = $this->people->get(CustomFieldEnum::NAME_REC_ID->value);

        return $value !== null ? (string) $value : null;
    }
}
