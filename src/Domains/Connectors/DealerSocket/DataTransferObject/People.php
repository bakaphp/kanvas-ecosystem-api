<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\People as DataTransferObjectPeople;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Locations\Models\Countries;

class People extends DataTransferObjectPeople
{
    public static function fromMultiple(
        UserInterface $user,
        AppInterface $app,
        Companies $company,
        array $people,
    ): self {
        $firstname = $people['firstName'];
        $country = Countries::getByCode('US');
        //$leadData = ! empty($people['events']) ? end($people['events']) : [];

        return People::from([
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'firstname' => $firstname,
            'lastname' => $people['lastName'] ?? '',
            'middlename' => $people['middleName'] ?? null,
            'dob' => $people['dateOfBirth'] ?? null,
            'contacts' => array_merge(
                array_map(
                    fn ($email) => [
                        'value' => $email['email'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0,
                    ],
                    $lead['customer']['emails'] ?? []
                ),
                array_map(
                    fn ($phone) => [
                        'value' => $phone['number'] ?? '',
                        'contacts_types_id' => isset($phone['type']) && strtolower($phone['type']) === 'mobile'
                            ? ContactTypeEnum::CELLPHONE->value
                            : ContactTypeEnum::PHONE->value,
                        'weight' => isset($phone['type']) && ((int)$phone['type'] === 1 || strtolower($phone['type']) === 'mobile')
                            ? 100
                            : 0,
                    ],
                    $lead['customer']['phones'] ?? []
                )
            ),
            'address' => ! empty($people['address'])
                ? [[
                    'address' => $people['address']['street'] ?? '',
                    'city' => $people['address']['city'] ?? '',
                    'state' => $people['address']['state'] ?? '',
                    'country' => $country->name,
                    'country_id' => $country->id,
                    'zip' => $people['address']['zipCode'] ?? '',
                ]]
                : [],
            'branch' => $company->defaultBranch,
            'custom_fields' => [
                CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value => $people['entityId'],
            ],
        ]);
    }
}
