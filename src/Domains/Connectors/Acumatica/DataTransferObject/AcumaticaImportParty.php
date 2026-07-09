<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;

/**
 * Normalizes a raw Acumatica party row (BAccount + Customer/Vendor, joined to the
 * default Contact + Address) into the shape PullCustomersAction feeds into the
 * Guild People DTO. Customer and Vendor share BAccount; the only difference is the
 * external-id custom field key.
 *
 * Expected joined columns: AcctCD, AcctName, LegalName, Status, NoteID,
 * FirstName, LastName, EMail, Phone1,
 * AddressLine1, AddressLine2, City, State, CountryID, PostalCode, Latitude, Longitude.
 */
class AcumaticaImportParty
{
    /**
     * @param array<array-key, mixed> $row
     *
     * @return array{firstname: string, lastname: ?string, organization: ?string, email: ?string, phone: ?string, address: ?array<string, mixed>, customFields: array<string, string>, sourceId: string}
     */
    public static function fromRow(array $row, bool $isVendor = false): array
    {
        $acctCd = trim((string) ($row['AcctCD'] ?? ''));
        $acctName = trim((string) ($row['AcctName'] ?? ''));
        $firstName = trim((string) ($row['FirstName'] ?? ''));
        $lastName = trim((string) ($row['LastName'] ?? ''));

        // firstname is required by the People DTO. Use the contact person if present,
        // otherwise fall back to the account (company) name, then the account code.
        $firstname = $firstName !== '' ? $firstName : ($acctName !== '' ? $acctName : $acctCd);

        $idField = $isVendor ? CustomFieldEnum::VENDOR_ID : CustomFieldEnum::CUSTOMER_ID;
        $customFields = [$idField->value => $acctCd];

        if (! empty($row['NoteID'])) {
            $customFields[CustomFieldEnum::NOTE_ID->value] = (string) $row['NoteID'];
        }

        return [
            'firstname' => $firstname,
            'lastname' => $lastName !== '' ? $lastName : null,
            'organization' => $acctName !== '' ? $acctName : null,
            'email' => ! empty($row['EMail']) ? trim((string) $row['EMail']) : null,
            'phone' => ! empty($row['Phone1']) ? trim((string) $row['Phone1']) : null,
            'address' => self::address($row),
            'customFields' => $customFields,
            'sourceId' => $acctCd,
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private static function address(array $row): ?array
    {
        $line1 = trim((string) ($row['AddressLine1'] ?? ''));

        if ($line1 === '') {
            return null;
        }

        return [
            'address' => $line1,
            'address_2' => ! empty($row['AddressLine2']) ? (string) $row['AddressLine2'] : null,
            'city' => ! empty($row['City']) ? (string) $row['City'] : null,
            'state' => ! empty($row['State']) ? (string) $row['State'] : null,
            'country' => ! empty($row['CountryID']) ? (string) $row['CountryID'] : null,
            'zip' => ! empty($row['PostalCode']) ? (string) $row['PostalCode'] : null,
            'latitude' => isset($row['Latitude']) ? (float) $row['Latitude'] : null,
            'longitude' => isset($row['Longitude']) ? (float) $row['Longitude'] : null,
        ];
    }
}
