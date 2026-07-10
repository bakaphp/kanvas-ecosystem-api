<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Spatie\LaravelData\Data;

/**
 * Customer and Vendor share BAccount; only the external-id custom-field key differs.
 */
class AcumaticaImportParty extends Data
{
    /**
     * @param array<string, string> $customFields
     */
    public function __construct(
        public readonly string $firstname,
        public readonly ?string $lastname,
        public readonly ?string $organization,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?AcumaticaImportAddress $address,
        public readonly array $customFields,
        public readonly string $sourceId,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row, bool $isVendor = false): self
    {
        $acctCd = trim((string) ($row['AcctCD'] ?? ''));
        $acctName = trim((string) ($row['AcctName'] ?? ''));
        $firstName = trim((string) ($row['FirstName'] ?? ''));
        $lastName = trim((string) ($row['LastName'] ?? ''));

        // People requires a firstname: contact person, else account name, else code.
        $firstname = $firstName !== '' ? $firstName : ($acctName !== '' ? $acctName : $acctCd);

        $idField = $isVendor ? CustomFieldEnum::VENDOR_ID : CustomFieldEnum::CUSTOMER_ID;
        $customFields = [$idField->value => $acctCd];

        if (! empty($row['NoteID'])) {
            $customFields[CustomFieldEnum::NOTE_ID->value] = (string) $row['NoteID'];
        }

        return new self(
            firstname: $firstname,
            lastname: $lastName !== '' ? $lastName : null,
            organization: $acctName !== '' ? $acctName : null,
            email: ! empty($row['EMail']) ? trim((string) $row['EMail']) : null,
            phone: ! empty($row['Phone1']) ? trim((string) $row['Phone1']) : null,
            address: self::address($row),
            customFields: $customFields,
            sourceId: $acctCd,
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function address(array $row): ?AcumaticaImportAddress
    {
        $line1 = trim((string) ($row['AddressLine1'] ?? ''));

        if ($line1 === '') {
            return null;
        }

        return new AcumaticaImportAddress(
            address: $line1,
            address_2: ! empty($row['AddressLine2']) ? (string) $row['AddressLine2'] : null,
            city: ! empty($row['City']) ? (string) $row['City'] : null,
            state: ! empty($row['State']) ? (string) $row['State'] : null,
            country: ! empty($row['CountryID']) ? (string) $row['CountryID'] : null,
            zip: ! empty($row['PostalCode']) ? (string) $row['PostalCode'] : null,
            latitude: isset($row['Latitude']) ? (float) $row['Latitude'] : null,
            longitude: isset($row['Longitude']) ? (float) $row['Longitude'] : null,
        );
    }
}
