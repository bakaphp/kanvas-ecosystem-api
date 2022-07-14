<?php

declare(strict_types=1);

namespace Kanvas\Companies\Companies\DataTransferObject;

use Illuminate\Http\Request;
use Spatie\DataTransferObject\DataTransferObject;

/**
 * AppsData class.
 */
class CompaniesPutData extends DataTransferObject
{
    /**
     * Construct function.
     *
     * @property int $currency_id
     * @property string $name
     * @property string $profile_image
     * @property string $website
     * @property string $address
     * @property string $zipcode
     * @property string $email
     * @property string $language
     * @property string $timezone
     * @property string $phone
     * @property string $country_code
     */
    public function __construct(
        public int $currency_id,
        public string $name,
        public string $profile_image,
        public string $website,
        public string $address,
        public string $zipcode,
        public string $email,
        public string $language,
        public string $timezone,
        public string $phone,
        public string $country_code,
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function fromRequest(Request $request) : self
    {
        return new self(
            currency_id: (int)$request->get('currency_id'),
            name: $request->get('name'),
            profile_image: $request->get('profile_image'),
            website: $request->get('website'),
            address: $request->get('address'),
            zipcode: $request->get('zipcode'),
            email: $request->get('email'),
            language: $request->get('language'),
            timezone: $request->get('timezone'),
            phone: $request->get('phone'),
            country_code: $request->get('country_code'),
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
            currency_id: (int)$data['currency_id'],
            name: $data['name'],
            profile_image: $data['profile_image'],
            website: $data['website'],
            address: $data['address'],
            zipcode: $data['zipcode'],
            email: $data['email'],
            language: $data['language'],
            timezone: $data['timezone'],
            phone:$data['phone'],
            country_code: $data['country_code'],
        );
    }
}
