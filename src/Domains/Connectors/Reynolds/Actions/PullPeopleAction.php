<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Entities\Customer;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Locations\Models\Countries;

/**
 * Maps a Reynolds Lead Update payload (the `Record` node) into a Kanvas People row.
 *
 * Expects the parsed array of a `rey_SalesAssistCRMPublishLeadUpdate.Record` element,
 * which contains either IndividualCustomer or BusinessCustomer plus Address,
 * PhoneNumbers, Email, and Consent.
 */
class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user
    ) {
    }

    public function execute(array $record): People
    {
        $customer = Customer::fromRecord($record);

        $customFields = [
            CustomFieldEnum::CONTACT_TYPE->value => $customer->isBusiness ? 'B' : 'I',
        ];

        if ($customer->nameRecId !== null) {
            $customFields[CustomFieldEnum::NAME_REC_ID->value] = $customer->nameRecId;
        }

        $customFields += $this->mapConsentCustomFields($customer);

        if ($customer->preferredContact !== null) {
            $customFields[CustomFieldEnum::PREFERRED_CONTACT->value] = $customer->preferredContact;
        }

        if ($customer->language !== null) {
            $customFields[CustomFieldEnum::LANGUAGE->value] = $customer->language;
        }

        if ($customer->sendTextsTo !== null) {
            $customFields[CustomFieldEnum::SEND_TEXTS_TO->value] = $customer->sendTextsTo;
        }

        $peopleData = PeopleData::from([
            'app' => $this->app,
            'branch' => $this->company->defaultBranch,
            'user' => $this->user,
            'firstname' => $customer->firstName ?? $customer->businessName ?? 'Unknown',
            'lastname' => $customer->lastName,
            'middlename' => $customer->middleName,
            'organization' => $customer->isBusiness ? $customer->businessName : null,
            'contacts' => $this->buildContacts($customer),
            'address' => $this->buildAddress($customer),
            'custom_fields' => $customFields,
        ]);

        return new SyncPeopleByThirdPartyCustomFieldAction($peopleData)->execute();
    }

    private function buildContacts(Customer $customer): array
    {
        $contacts = [];

        if ($customer->email !== null) {
            $contacts[] = [
                'value' => $customer->email,
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0,
            ];
        }

        foreach ($customer->phones as $phone) {
            $num = preg_replace('/\D+/', '', (string) ($phone['num'] ?? ''));
            if ($num === '') {
                continue;
            }

            $contacts[] = [
                'value' => $num,
                'contacts_types_id' => $this->mapPhoneType((string) ($phone['type'] ?? '')),
                'weight' => ($phone['type'] ?? '') === 'C' ? 100 : 0,
            ];
        }

        return $contacts;
    }

    private function buildAddress(Customer $customer): array
    {
        if (empty($customer->address)) {
            return [];
        }

        $countryCode = $customer->address['Country'] ?? 'US';
        $country = Countries::getByCode($countryCode) ?? Countries::getByCode('US');

        return [[
            'address' => $customer->address['Addr1'] ?? '',
            'address_2' => $customer->address['Addr2'] ?? '',
            'city' => $customer->address['City'] ?? '',
            'state' => $customer->address['State'] ?? '',
            'zip' => $customer->address['Zip'] ?? '',
            'county' => $customer->address['County'] ?? '',
            'countries_id' => $country?->getId(),
        ]];
    }

    private function mapPhoneType(string $reynoldsType): int
    {
        return match ($reynoldsType) {
            'C' => ContactTypeEnum::CELLPHONE->value,
            'B' => ContactTypeEnum::WORK_PHONE->value,
            default => ContactTypeEnum::PHONE->value,
        };
    }

    private function mapConsentCustomFields(Customer $customer): array
    {
        $mapping = [
            CustomFieldEnum::CONSENT_EMAIL->value => $customer->consent['Email'] ?? null,
            CustomFieldEnum::CONSENT_TEXT->value => $customer->consent['Text'] ?? null,
            CustomFieldEnum::CONSENT_PHONE->value => $customer->consent['Phone'] ?? null,
            CustomFieldEnum::CONSENT_MAIL->value => $customer->consent['Mail'] ?? null,
            CustomFieldEnum::CONSENT_OPT_OUT->value => $customer->consent['OptOut'] ?? null,
            CustomFieldEnum::CONSENT_OPT_OUT_USE->value => $customer->consent['OptOutUse'] ?? null,
        ];

        return array_filter($mapping, fn ($v) => $v !== null && $v !== '');
    }
}
