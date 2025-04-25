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
        $people->firstname = $verificationData['idcheck']['data']['firstName'] ?? $people->firstname;
        $people->middlename = $verificationData['idcheck']['data']['middleName'] ?? $people->middlename;
        $people->lastname = $verificationData['idcheck']['data']['lastName'] ?? $people->lastname;
        $people->name = $verificationData['idcheck']['data']['firstName'] . ' ' . $verificationData['idcheck']['data']['lastName'];
        $people->dob = isset($verificationData['idcheck']['data']['dateOfBirth'])
            ? Carbon::createFromFormat('m/d/Y', $verificationData['idcheck']['data']['dateOfBirth'])->format('Y-m-d')
            : $people->dob;
        $people->saveOrFail();

        // Check if address data exists before adding it
        if (
            isset($verificationData['idcheck']['data']['address1']) ||
            isset($verificationData['idcheck']['data']['city']) ||
            isset($verificationData['idcheck']['data']['state']) ||
            isset($verificationData['idcheck']['data']['postalCode'])
        ) {
            $people->addAddress(new Address(
                address: $verificationData['idcheck']['data']['address1'] ?? '',
                city: $verificationData['idcheck']['data']['city'] ?? '',
                state: $verificationData['idcheck']['data']['state'] ?? '',
                country: $verificationData['idcheck']['data']['country'] ?? Countries::getByCode('US')->name,
                zip: $verificationData['idcheck']['data']['postalCode'] ?? '',
            ));
        }
    }
}
