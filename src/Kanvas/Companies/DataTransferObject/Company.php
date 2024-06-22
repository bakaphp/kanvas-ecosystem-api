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
        public ?string $website,
        public ?string $address,
        public ?int $zipcode,
        public ?string $email,
        public ?string $language,
        public ?string $timezone,
        public ?string $phone,
        public ?string $country_code,
        public ?int $currency_id = null,
        public ?array $files = null,
        public array $custom_fields = [],
        public bool $is_active = true
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        return new self(
            $user,
            currency_id: (int)$request['currency_id'],
            name: $request['name'],
            website: $request['website'] ?? null,
            address: $request['address'] ?? null,
            zipcode: (int) $request['zipcode'],
            email: $request['email'] ?? null,
            language: $request['language'] ?? null,
            timezone: $request['timezone'] ?? null,
            phone: $request['phone'] ?? null,
            country_code: $request['country_code'] ?? null,
            files: $request['files'] ?? null,
            custom_fields: $request['custom_fields'] ?? [],
            is_active: $request['is_active'] ?? true
        );
    }
}
