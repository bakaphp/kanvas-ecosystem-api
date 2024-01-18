<?php

declare(strict_types=1);

namespace Kanvas\Companies\DataTransferObject;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelData\Data;

/**
 * CompaniesPostData class.
 */
class CompaniesPostData extends Data
{
    /**
     * Construct function.
     *
     * @param int|null $users_id
     */
    public function __construct(
        public string $name,
        public int $users_id,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $currency_id = null,
        public ?string $website = null,
        public ?string $address = null,
        public ?int $zipcode = null,
        public ?string $language = null,
        public ?string $timezone = null,
        public ?string $country_code = null,
        public ?array $files = null,
        public ?bool $is_active = true
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     */
    public static function viaRequest(Request $request): self
    {
        return new self(
            users_id: Auth::user()->id,
            name: $request->get('name'),
        );
    }

    /**
     * Create new instance of DTO from Console Command.
     *
     * @param array $data Input data
     */
    public static function fromConsole(array $data): self
    {
        return new self(
            name: $data['name'],
            users_id : $data['users_id'],
            email : $data['email'] ?? null,
            phone : $data['phone'] ?? null,
            currency_id : $data['currency_id'] ?? null,
            website : $data['website'] ?? null,
            address : $data['address'] ?? null,
            zipcode : $data['zipcode'] ?? null,
            language : $data['language'] ?? null,
            timezone : $data['timezone'] ?? null,
            country_code : $data['country_code'] ?? null,
        );
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
            email : $data['email'] ?? null,
            phone : $data['phone'] ?? null,
            currency_id : $data['currency_id'] ?? null,
            website : $data['website'] ?? null,
            address : $data['address'] ?? null,
            zipcode : $data['zipcode'] ?? null,
            language : $data['language'] ?? null,
            timezone : $data['timezone'] ?? null,
            country_code : $data['country_code'] ?? null,
            files: $data['files'] ?? null
        );
    }
}
