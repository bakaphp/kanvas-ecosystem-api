<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\DataTransferObject;

use Baka\Support\Str;
use Baka\Validations\Date;
use Baka\Validations\Phone;
use Carbon\Carbon;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\Data;

class Customer extends Data
{
    public function __construct(
        public readonly bool $isBusiness,
        public readonly string $firstName,
        public readonly ?string $lastName = null,
        public readonly ?string $middleName = null,
        public readonly ?string $birthday = null,
        public readonly array $emails = [],
        public readonly array $phones = [],
        public readonly array $address = [],
    ) {
    }

    public static function fromPeople(People $people): self
    {
        $name = self::extractNameParts($people);
        $generateTempEmailOnCreation = empty($people->get(CustomFieldEnum::CUSTOMER_ID->value));

        $emails = self::buildEmails($people, $name, $generateTempEmailOnCreation);
        $phones = self::buildPhones($people);
        $address = self::buildAddress($people);

        $dob = Date::isValid($people->dob) ? $people->dob : null;

        return new self(
            isBusiness: false,
            firstName: $name['firstName'],
            lastName: $name['lastName'] ?: '-',
            middleName: $name['middleName'],
            birthday: $dob instanceof Carbon ? $dob->toDateString() : $dob,
            emails: $emails,
            phones: $phones,
            address: $address,
        );
    }

    private static function extractNameParts(People $people): array
    {
        /*  $name = $people->getFirstAndLastName();
         $words = explode(' ', $name['lastName']);
         $middleName = null;

         if (count($words) >= 2) {
             $middleName = $words[0];
             $name['lastName'] = implode(' ', array_slice($words, 1));
         } */

        return [
            'firstName' => $people->firstname, //$name['firstName'],
            'middleName' => $people->middlename,
            'lastName' => $people->lastname,
        ];
    }

    private static function buildEmails(People $people, array $name, bool $generateTempEmailOnCreation): array
    {
        $emails = $people->getEmails();
        $email = $emails->first();

        // Valid email exists - use it
        if ($email && filter_var($email->value, FILTER_VALIDATE_EMAIL)) {
            return [[
                'address' => $email->value,
                'emailType' => 'Personal',
                'doNotEmail' => (bool) $email->is_opt_out,
            ]];
        }

        // No valid email - only generate temp if flag is set
        if (! $generateTempEmailOnCreation) {
            return [];
        }

        $tempEmail = self::generateTempEmail($name);

        // Only persist if no emails exist at all
        if ($emails->isEmpty()) {
            $people->addEmail($tempEmail);
        }

        return [[
            'address' => $tempEmail,
            'emailType' => 'Personal',
        ]];
    }

    private static function buildPhones(People $people): array
    {
        $peoplePhones = $people->getCellPhones()->count() ? $people->getCellPhones() : $people->getPhones();
        $phonesData = [];

        if (! $peoplePhones->count()) {
            return [];
        }

        foreach ($peoplePhones as $phone) {
            if (Phone::isTollFreeNumber($phone->value) || empty($phone->value)) {
                continue;
            }

            $phoneValue = preg_replace('/\D+/', '', $phone->value);

            $isOptOut = $phone->is_opt_out === 1;

            $phonesData[] = [
                'number' => $phoneValue,
                'phoneType' => 'Cellular',
                'preferredTimeToContact' => 'Unspecified',
                //'doNotCall' => $isOptOut,
                'doNotText' => $isOptOut,
            ];

            // Only add first valid phone
            break;
        }

        return $phonesData;
    }

    private static function buildAddress(People $people): array
    {
        $addressCollection = $people->address()->get();
        $firstAddress = $addressCollection->first();

        if (
            ! $addressCollection->count() ||
            empty(trim((string) $firstAddress->zip)) ||
            empty(trim((string) $firstAddress->city)) ||
            empty(trim((string) $firstAddress->state)) ||
            empty(trim((string) $firstAddress->address))
        ) {
            return [];
        }

        return [
            'addressLine1' => $firstAddress->address,
            'addressLine2' => $firstAddress->address_2 ?? '',
            'city' => $firstAddress->city,
            'state' => $firstAddress->state,
            'zip' => $firstAddress->zip,
            'country' => $firstAddress->country->code ?? 'US',
        ];
    }

    private static function generateTempEmail(array $name): string
    {
        return preg_replace('/\s+/', '', Str::cleanup($name['firstName'] . $name['lastName'])) . '@salesassist.io';
    }
}
