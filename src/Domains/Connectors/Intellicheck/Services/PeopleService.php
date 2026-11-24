<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Services;

use Carbon\Carbon;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Locations\Models\Countries;

class PeopleService
{
    public static function updatePeopleInformation(People $people, array $verificationData): void
    {
        $idCheck = $verificationData['idcheck']['data'] ?? [];

        $people->firstname = $idCheck['firstName'] ?? $people->firstname;
        $people->middlename = $idCheck['middleName'] ?? $people->middlename;
        $people->lastname = $idCheck['lastName'] ?? $people->lastname;
        $people->name = $people->firstname . ' ' . $people->lastname;
        $people->dob = isset($idCheck['dateOfBirth'])
            ? Carbon::createFromFormat('m/d/Y', $idCheck['dateOfBirth'])->format('Y-m-d')
            : $people->dob;
        $people->saveOrFail();

        if (
            isset($idCheck['address1']) ||
            isset($idCheck['city']) ||
            isset($idCheck['state']) ||
            isset($idCheck['postalCode'])
        ) {
            self::setDefaultAddress(
                $people,
                $idCheck['address1'] ?? '',
                $idCheck['city'] ?? '',
                $idCheck['state'] ?? '',
                $idCheck['postalCode'] ?? '',
                $idCheck['country'] ?? Countries::getByCode('US')->name,
            );
        }
    }

    /**
     * The manual id-verification form (status opened, `data.address`) that the dealer CRM
     * activities share; the scan path above is the Intellicheck envelope.
     */
    public static function updatePeopleAddressFromForm(People $people, array $form): void
    {
        self::setDefaultAddress(
            $people,
            (string) ($form['address'] ?? ''),
            (string) ($form['city'] ?? ''),
            (string) ($form['state'] ?? ''),
            (string) ($form['zipcode'] ?? ''),
        );
    }

    private static function setDefaultAddress(
        People $people,
        string $address,
        string $city,
        string $state,
        string $zip,
        ?string $country = null,
    ): void {
        $people->addDefaultAddress(new Address(
            address: $address,
            city: $city,
            state: $state,
            country: $country,
            zip: $zip,
        ));
    }
}
