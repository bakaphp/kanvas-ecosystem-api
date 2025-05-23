<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Guild\Customers\DataTransferObject\People as GuildPeople;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Users\Models\Users;

class People extends GuildPeople
{
    public static function fromDriveCentric(
        Apps $app,
        Companies $company,
        Users $user,
        array $data
    ): self {
        return self::from([
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'firstname' => $data['firstName'],
            'lastname' => $data['lastName'],
            'name' => $data['firstName'] . ' ' . $data['middleName'] . ' ' . $data['lastName'],
            'middlename' => $data['middleName'],
            'contacts' => array_merge(
                array_map(
                    fn ($email) => [
                        'value' => $email['value'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 100,
                    ],
                    $data['emails']
                ),
                array_map(
                    fn ($phone) => [
                        'value' => $phone['value'],
                        'contacts_types_id' => ContactTypeEnum::PHONE->value,
                        'weight' => 100,
                    ],
                    $data['phones']
                )
            ),
            'address' => array_map(
                fn ($address) => [
                    'address' => $address['line1'] ?? ' ',
                    'city' => $address['city'] ?? ' ',
                    'state' => $address['stateOrProvince'] ?? ' ',
                    'country' => $address['countryCode'] ?? ' ',
                    'postal_code' => $address['zipOrPostalCode'] ?? ' ',
                    'county' => $address['county'] ?? ' ',
                ],
                $data['addresses']
            ),
            'custom_fields' => [
                CustomFieldEnums::DRIVE_CENTRIC_ID->value => $data['customerId'] ?? null,
            ]
        ]);
    }
}
