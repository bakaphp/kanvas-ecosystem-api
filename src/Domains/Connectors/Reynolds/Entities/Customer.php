<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Entities;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Locations\Models\Countries;

class Customer
{
    public function __construct(
        public ?string $nameRecId = null,
        public bool $isBusiness = false,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $middleName = null,
        public ?string $businessName = null,
        public ?string $businessContactFirstName = null,
        public ?string $businessContactLastName = null,
        public ?string $prefix = null,
        public ?string $suffix = null,
        public ?string $language = null,
        public ?string $preferredContact = null,
        public ?string $sendTextsTo = null,
        public array $address = [],
        public array $phones = [],
        public ?string $email = null,
        public array $consent = [],
    ) {
    }

    /**
     * Build from the parsed payload of `rey_SalesAssistCRMPublishLeadUpdate.Record`.
     */
    public static function fromRecord(array $record): self
    {
        $isBusiness = isset($record['BusinessCustomer']);
        $individual = $record['IndividualCustomer'] ?? [];
        $business = $record['BusinessCustomer'] ?? [];
        $address = $record['Address'] ?? [];
        $phoneNumbers = $record['PhoneNumbers']['Phone'] ?? [];
        $email = $record['Email']['MailTo'] ?? null;
        $consent = $record['Consent'] ?? [];

        $phones = self::normalizePhones($phoneNumbers);

        if ($isBusiness) {
            return new self(
                nameRecId: $business['NameRecId'] ?? null,
                isBusiness: true,
                firstName: $business['ContactFirstName'] ?? null,
                lastName: $business['ContactLastName'] ?? null,
                businessName: $business['BusinessName'] ?? null,
                businessContactFirstName: $business['ContactFirstName'] ?? null,
                businessContactLastName: $business['ContactLastName'] ?? null,
                prefix: $business['Salut'] ?? null,
                language: $business['Language'] ?? null,
                preferredContact: $business['PrefContact'] ?? null,
                sendTextsTo: $business['SendTextsTo'] ?? null,
                address: $address,
                phones: $phones,
                email: $email,
                consent: $consent,
            );
        }

        return new self(
            nameRecId: $individual['NameRecId'] ?? null,
            isBusiness: false,
            firstName: $individual['FirstName'] ?? null,
            lastName: $individual['LastName'] ?? null,
            middleName: $individual['MidName'] ?? null,
            prefix: $individual['Salut'] ?? null,
            suffix: $individual['Suffix'] ?? null,
            language: $individual['Language'] ?? null,
            preferredContact: $individual['PrefContact'] ?? null,
            sendTextsTo: $individual['SendTextsTo'] ?? null,
            address: $address,
            phones: $phones,
            email: $email,
            consent: $consent,
        );
    }

    /**
     * Normalize R&R phone shape to a simple list of [type, num].
     * R&R can send either a single Phone object or a list of them.
     */
    private static function normalizePhones(array $phoneNumbers): array
    {
        if (isset($phoneNumbers['Type']) || isset($phoneNumbers['Num'])) {
            $phoneNumbers = [$phoneNumbers];
        }

        return array_map(
            fn (array $phone) => [
                'type' => $phone['Type'] ?? null,
                'num' => $phone['Num'] ?? null,
            ],
            $phoneNumbers
        );
    }

    public function displayName(): string
    {
        if ($this->isBusiness) {
            return (string) ($this->businessName ?? trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? '')));
        }

        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    /**
     * Project this Customer onto a Kanvas People DTO.
     *
     * NAME_REC_ID is the first custom field on purpose:
     * SyncPeopleByThirdPartyCustomFieldAction picks array_keys()[0] as the
     * lookup key, so the DMS Customer Id is what disambiguates the People
     * row across reimports. If $existingPeopleId is supplied the DTO is
     * tagged with it so callers that already loaded the People row can
     * short-circuit the duplicate-detection lookup.
     */
    public function toPeopleData(
        AppInterface $app,
        CompaniesBranches $branch,
        UserInterface $user,
        ?int $existingPeopleId = null,
        ?string $fallbackNameRecId = null
    ): PeopleData {
        $nameRecId = $this->nameRecId ?? $fallbackNameRecId;

        if ($nameRecId === null) {
            throw new ReynoldsException(
                'Customer is missing NameRecId — cannot build PeopleData without an external identifier'
            );
        }

        $customFields = [
            CustomFieldEnum::NAME_REC_ID->value => $nameRecId,
            CustomFieldEnum::CONTACT_TYPE->value => $this->isBusiness ? 'B' : 'I',
        ];
        $customFields += $this->buildConsentCustomFields();

        if ($this->preferredContact !== null) {
            $customFields[CustomFieldEnum::PREFERRED_CONTACT->value] = $this->preferredContact;
        }
        if ($this->language !== null) {
            $customFields[CustomFieldEnum::LANGUAGE->value] = $this->language;
        }
        if ($this->sendTextsTo !== null) {
            $customFields[CustomFieldEnum::SEND_TEXTS_TO->value] = $this->sendTextsTo;
        }

        $payload = [
            'app' => $app,
            'branch' => $branch,
            'user' => $user,
            'firstname' => $this->firstName ?? $this->businessName ?? 'Unknown',
            'lastname' => $this->lastName,
            'middlename' => $this->middleName,
            'organization' => $this->isBusiness ? $this->businessName : null,
            'contacts' => $this->buildContactsForPeopleData(),
            'address' => $this->buildAddressForPeopleData(),
            'custom_fields' => $customFields,
        ];

        if ($existingPeopleId !== null) {
            $payload['id'] = $existingPeopleId;
        }

        return PeopleData::from($payload);
    }

    private function buildContactsForPeopleData(): array
    {
        $contacts = [];

        if ($this->email !== null) {
            $contacts[] = [
                'value' => $this->email,
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => 0,
            ];
        }

        foreach ($this->phones as $phone) {
            $num = preg_replace('/\D+/', '', (string) ($phone['num'] ?? ''));
            if ($num === '') {
                continue;
            }

            $contacts[] = [
                'value' => $num,
                'contacts_types_id' => self::mapPhoneType((string) ($phone['type'] ?? '')),
                'weight' => ($phone['type'] ?? '') === 'C' ? 100 : 0,
            ];
        }

        return $contacts;
    }

    private function buildAddressForPeopleData(): array
    {
        if (empty($this->address)) {
            return [];
        }

        $countryCode = $this->address['Country'] ?? 'US';
        $country = Countries::getByCode($countryCode) ?? Countries::getByCode('US');

        return [[
            'address' => $this->address['Addr1'] ?? '',
            'address_2' => $this->address['Addr2'] ?? '',
            'city' => $this->address['City'] ?? '',
            'state' => $this->address['State'] ?? '',
            'zip' => $this->address['Zip'] ?? '',
            'county' => $this->address['County'] ?? '',
            'countries_id' => $country?->getId(),
        ]];
    }

    private static function mapPhoneType(string $reynoldsType): int
    {
        return match ($reynoldsType) {
            'C' => ContactTypeEnum::CELLPHONE->value,
            'B' => ContactTypeEnum::WORK_PHONE->value,
            default => ContactTypeEnum::PHONE->value,
        };
    }

    private function buildConsentCustomFields(): array
    {
        $mapping = [
            CustomFieldEnum::CONSENT_EMAIL->value => $this->consent['Email'] ?? null,
            CustomFieldEnum::CONSENT_TEXT->value => $this->consent['Text'] ?? null,
            CustomFieldEnum::CONSENT_PHONE->value => $this->consent['Phone'] ?? null,
            CustomFieldEnum::CONSENT_MAIL->value => $this->consent['Mail'] ?? null,
            CustomFieldEnum::CONSENT_OPT_OUT->value => $this->consent['OptOut'] ?? null,
            CustomFieldEnum::CONSENT_OPT_OUT_USE->value => $this->consent['OptOutUse'] ?? null,
        ];

        return array_filter($mapping, fn ($v) => $v !== null && $v !== '');
    }
}
