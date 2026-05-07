<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Guild\Customers\DataTransferObject\People as GuildPeople;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People as PeopleModel;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\Users;

class People extends GuildPeople
{
    public static function fromDriveCentric(
        Apps $app,
        Companies $company,
        Users $user,
        array $data
    ): self {
        $country = Countries::getByCode('US');

        $middleName = ! empty($data['middleName']) ? ' ' . $data['middleName'] . ' ' : ' ';
        $name = trim(($data['firstName'] ?? '') . $middleName . ($data['lastName'] ?? ''));

        return self::from([
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'firstname' => $data['firstName'] ?? '',
            'lastname' => $data['lastName'] ?? '',
            'name' => $name,
            'middlename' => $data['middleName'] ?? null,
            'dob' => $data['birthdate'] ?? null,
            'contacts' => array_merge(
                self::mapEmails($data['emails'] ?? []),
                self::mapPhones($data['phones'] ?? [])
            ),
            'address' => self::mapAddresses($data['addresses'] ?? [], $country),
            'branch' => $company->defaultBranch,
            'custom_fields' => [
                CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value => self::getCustomerId($data),
            ],
        ]);
    }

    /**
     * Map email data to contacts format.
     */
    protected static function mapEmails(array $emails): array
    {
        return array_map(
            fn ($email) => [
                'value' => $email['value'] ?? $email['emailAddress'] ?? '',
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'weight' => self::getEmailWeight($email),
            ],
            $emails
        );
    }

    /**
     * Map phone data to contacts format.
     */
    protected static function mapPhones(array $phones): array
    {
        return array_map(
            fn ($phone) => [
                'value' => $phone['value'] ?? $phone['number'] ?? '',
                'contacts_types_id' => self::getPhoneType($phone),
                'weight' => self::getPhoneWeight($phone),
            ],
            $phones
        );
    }

    /**
     * Map address data.
     */
    protected static function mapAddresses(array $addresses, Countries $country): array
    {
        return array_map(
            fn ($address) => [
                'address' => $address['line1'] ?? $address['streetAddress'] ?? ' ',
                'address_2' => $address['line2'] ?? '',
                'city' => $address['city'] ?? ' ',
                'state' => $address['stateOrProvince'] ?? $address['state'] ?? ' ',
                'country' => $country->name,
                'country_id' => $country->id,
                'zip' => $address['zipOrPostalCode'] ?? $address['postalCode'] ?? ' ',
                'county' => $address['county'] ?? ' ',
                'is_default' => ($address['type'] ?? 'Primary') === 'Primary',
            ],
            $addresses
        );
    }

    /**
     * Get customer ID from data.
     */
    protected static function getCustomerId(array $data): string
    {
        // Try direct customerId field
        if (isset($data['customerId'])) {
            return (string) $data['customerId'];
        }

        // Try to find CrmId in identifiers
        foreach ($data['identifiers'] ?? [] as $identifier) {
            if (($identifier['type'] ?? '') === 'CrmId') {
                return $identifier['value'] ?? null;
            }
        }

        return (string) $data['id'];
    }

    /**
     * Get email weight based on type.
     */
    protected static function getEmailWeight(array $email): int
    {
        $type = strtolower($email['type'] ?? $email['emailType'] ?? 'primary');

        return match ($type) {
            'primary' => 100,
            'work' => 80,
            default => 50,
        };
    }

    /**
     * Get phone contact type.
     */
    protected static function getPhoneType(array $phone): int
    {
        $type = strtolower($phone['type'] ?? $phone['phoneType'] ?? 'home');

        return match ($type) {
            'cell', 'mobile' => ContactTypeEnum::CELLPHONE->value,
            default => ContactTypeEnum::PHONE->value,
        };
    }

    /**
     * Get phone weight based on type.
     */
    protected static function getPhoneWeight(array $phone): int
    {
        $type = strtolower($phone['type'] ?? $phone['phoneType'] ?? 'home');

        return match ($type) {
            'cell', 'mobile' => 100,
            'home' => 80,
            'work' => 60,
            default => 50,
        };
    }

    public static function toDriveCentric(PeopleModel $people): array
    {
        $phones = $people->phones->map(function ($phone) {
            return [
                'type' => $phone->contacts_types_id === ContactTypeEnum::CELLPHONE->value ? 'Mobile' : 'Home',
                'value' => $phone->value,
            ];
        })->toArray();

        $emails = $people->emails->map(function ($email) {
            return [
                'type' => 'Home',
                'value' => $email->value,
            ];
        })->toArray();

        $addresses = $people->address()->get()->map(function ($address) {
            return [
                'line1' => $address->address ?? '',
                'line2' => $address->address_2 ?? '',
                'city' => $address->city ?? '',
                'stateOrProvince' => $address->state ?? '',
                'zipOrPostalCode' => $address->zip ?? '',
                'countryCode' => 'US',
                'county' => $address->county ?? '',
                'type' => $address->is_default ? 'Primary' : 'Other',
            ];
        })->toArray();

        return [
            'firstName' => $people->firstname,
            'middleName' => $people->middlename ?? '',
            'lastName' => $people->lastname,
            'birthdate' => $people->dob,
            'phones' => $phones,
            'emails' => $emails,
            'addresses' => $addresses,
            'customerType' => 'Individual',
            'identifiers' => [
                [
                    'type' => 'PartnerId',
                    'value' => (string) $people->getId(),
                ],
            ],
        ];
    }
}
