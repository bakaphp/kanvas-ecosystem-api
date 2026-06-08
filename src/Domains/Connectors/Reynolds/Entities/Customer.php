<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Entities;

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
}
