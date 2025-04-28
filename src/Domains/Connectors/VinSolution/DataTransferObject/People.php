<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Locations\Models\Countries;

class People extends PeopleDTO
{
    public static function fromContact(
        Contact $customer,
        AppInterface $app,
        Companies $company,
        UserInterface $user
    ): self {
        $country = Countries::getByCode('US');

        $middleName = isset($customer->information['MiddleName']) && ! empty($customer->information['MiddleName']) ? ' ' . $customer->information['MiddleName'] . ' ' : ' ';
        $name = $customer->information['FirstName'] . $middleName . $customer->information['LastName'];

        return self::from([
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'firstname' => $customer->information['FirstName'],
            'lastname' => $customer->information['LastName'],
            'name' => $name,
            'middlename' => $middleName,
            //'dob' => $customer->birthday ?? null,
            'contacts' => array_merge(
                array_map(
                    fn ($email) => [
                        'value' => $email['EmailAddress'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => $email['EmailType'] === 'Primary' ? 100 : 0,
                    ],
                    $customer->emails
                ),
                array_map(
                    fn ($phone) => [
                        'value' => $phone['Number'],
                        'contacts_types_id' => ContactTypeEnum::PHONE->value,
                        'weight' => (int) $phone['PhoneId'] === 1 ? 100 : $phone['PhoneId'],
                    ],
                    $customer->phones
                )
            ),
            'address' =>
                array_map(
                    fn ($address) => [
                        'address' => $address['StreetAddress'] ?? ' ',
                        'city' => $address['City'] ?? ' ',
                        'state' => $address['State'] ?? ' ',
                        'zip' => $address['PostalCode'] ?? ' ',
                        'county' => $address['County'] ?? ' ',
                        'country' => $country->name,
                        'country_id' => $country->id,
                    ],
                    $customer->information['Addresses'] ?? []
                ),
            'branch' => $company->defaultBranch,
            'custom_fields' => [
                CustomFieldEnum::CONTACT->value => $customer->id,
            ],
        ]);
    }
}
