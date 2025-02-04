<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * CompaniesBranchPostData class.
 */
class CompaniesBranchPostData extends Data
{
    /**
     * Construct function.
     *
     * @param int|null $users_id
     */
    public function __construct(
        public string $name,
        public int $companies_id,
        public int $users_id,
        public int $is_default = 0,
        public bool $is_active = true,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $zipcode = null,
        public ?array $files = null,
        public ?string $address = null,
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

    /**
     * Create new instance of DTO from array of data.
     *
     * @param array $data Input data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            users_id : $data['users_id'],
            companies_id : (int) $data['companies_id'],
            is_default : (int) $data['is_default'],
            email : $data['email'] ?? null,
            phone : $data['phone'] ?? null,
            address : $data['address'] ?? null,
            zipcode : $data['zipcode'] ?? null,
            files: $data['files'] ?? null,
            is_active: $data['is_active'] ?? true,
            countries_id: $data['countries_id'] ?? null,
            states_id: $data['states_id'] ?? null,
            cities_id: $data['cities_id'] ?? null,
            address_2: $data['address_2'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
            country: $data['country'] ?? null,
            zip: $data['zip'] ?? null
        );
    }
}
