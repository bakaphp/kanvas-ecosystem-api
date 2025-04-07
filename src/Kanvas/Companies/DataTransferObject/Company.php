<?php

declare(strict_types=1);

namespace Kanvas\Companies\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class Company extends Data
{
    public function __construct(
        public UserInterface $user,
        public string $name,
        public ?string $website = null,
        public ?string $address = null,
        public ?int $zipcode = null,
        public ?string $email = null,
        public ?string $language = null,
        public ?string $timezone = null,
        public ?string $phone = null,
        public ?string $country_code = null,
        public ?int $currency_id = null,
        public ?array $files = null,
        public array $custom_fields = [],
        public bool $is_active = true,
        public ?int $countries_id = null,
        public ?int $states_id = null,
        public ?int $cities_id = null,
        public ?string $address_2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $country = null,
        public ?string $zip = null
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        return new self(
            user: $user,
            currency_id: ! empty($request['currency_id']) ? (int) $request['currency_id'] : null,
            name: $request['name'],
            website: $request['website'] ?? null,
            address: $request['address'] ?? null,
            zipcode: ! empty($request['zipcode']) ? (int) $request['zipcode'] : null,
            email: $request['email'] ?? null,
            language: $request['language'] ?? null,
            timezone: $request['timezone'] ?? null,
            phone: $request['phone'] ?? null,
            country_code: $request['country_code'] ?? null,
            files: $request['files'] ?? null,
            custom_fields: $request['custom_fields'] ?? [],
            is_active: $request['is_active'] ?? true,
            countries_id: ! empty($request['countries_id']) ? (int) $request['countries_id'] : null,
            states_id: ! empty($request['states_id']) ? (int) $request['states_id'] : null,
            cities_id: ! empty($request['cities_id']) ? (int) $request['cities_id'] : null,
            address_2: $request['address_2'] ?? null,
            city: $request['city'] ?? null,
            state: $request['state'] ?? null,
            country: $request['country'] ?? null,
            zip: $request['zip'] ?? null
        );
    }
}
