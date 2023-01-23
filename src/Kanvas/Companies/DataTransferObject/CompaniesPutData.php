<?php

declare(strict_types=1);

namespace Kanvas\Companies\DataTransferObject;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class CompaniesPutData extends Data
{
    /**
     * Construct function.
     *
     * @property string $name
     * @property int|null $currency_id
     * @property string|null $website
     * @property string|null $address
     * @property int|null $zipcode
     * @property string|null $email
     * @property string|null $language
     * @property string|null $timezone
     * @property string|null $phone
     * @property string|null $country_code
     * @property array|null $files
     */
    public function __construct(
        public string $name,
        public ?int $currency_id = null,
        public ?string $website,
        public ?string $address,
        public ?int $zipcode,
        public ?string $email,
        public ?string $language,
        public ?string $timezone,
        public ?string $phone,
        public ?string $country_code,
        public ?array $files = null
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function viaRequest(Request $request) : self
    {
        return new self(
            currency_id: (int)$request->get('currency_id'),
            name: $request->get('name'),
            website: $request->get('website'),
            address: $request->get('address'),
            zipcode: (int) $request->get('zipcode'),
            email: $request->get('email'),
            language: $request->get('language'),
            timezone: $request->get('timezone'),
            phone: $request->get('phone'),
            country_code: $request->get('country_code'),
            files: $request->get('files') ?? null
        );
    }

    /**
     * Create new instance of DTO from array of data.
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromArray(array $data) : self
    {
        return new self(
            name: $data['name'],
            currency_id: $data['currency_id'] ?? null,
            website: $data['website'] ?? null,
            address: $data['address'] ?? null,
            zipcode: $data['zipcode'] ?? null,
            email: $data['email'] ?? null,
            language: $data['language'] ?? null,
            timezone: $data['timezone'] ?? null,
            phone:$data['phone'] ?? null,
            country_code: $data['country_code'] ?? null,
            files: $data['files'] ?? null,
        );
    }
}
