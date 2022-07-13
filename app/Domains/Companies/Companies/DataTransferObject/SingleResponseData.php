<?php

declare(strict_types=1);

namespace Kanvas\Companies\Companies\DataTransferObject;

use Kanvas\Companies\Companies\Models\Companies;
use Spatie\DataTransferObject\DataTransferObject;

/**
 * ResponseData class.
 */
class SingleResponseData extends DataTransferObject
{
    /**
     * Construct function.
     *
     * @property int $id
     * @property int $users_id
     * @property int $system_modules_id
     * @property int $currency_id
     * @property string $uuid
     * @property string $name
     * @property string $profile_image
     * @property string $website
     * @property string $address
     * @property string $zipcode
     * @property string $email
     * @property string $language
     * @property string $timezone
     * @property string $phone
     * @property int $has_activities
     * @property string $country_code
     */
    public function __construct(
        public int $id,
        public int $users_id,
        public ?int $system_modules_id,
        public ?int $currency_id,
        public ?string $uuid,
        public string $name,
        public ?string $profile_image,
        public ?string $website,
        public ?string $address,
        public ?string $zipcode,
        public ?string $email,
        public ?string $language,
        public ?string $timezone,
        public ?string $phone,
        public ?string $created_at,
        public ?string $updated_at,
        public int $is_deleted,
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Companies $company
     *
     * @return self
     */
    public static function fromModel(Companies $company) : self
    {
        //Here we could filter the data we need

        return new self(
            id: $company->id,
            users_id: $company->users_id,
            system_modules_id: $company->system_modules_id,
            currency_id: $company->currency_id,
            uuid: $company->uuid,
            name: $company->name,
            profile_image: $company->profile_image,
            website: $company->website,
            address: $company->address,
            zipcode: $company->zipcode,
            email: $company->email,
            language: $company->language,
            timezone: $company->timezone,
            phone: $company->phone,
            created_at: $company->created_at->format('Y-m-d H:i:s'),
            updated_at: $company->updated_at->format('Y-m-d H:i:s'),
            is_deleted: $company->is_deleted,
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
            id: (int)$data['id'],
            users_id: (int)$data['users_id'],
            system_modules_id: (int)$data['system_modules_id'],
            currency_id: (int)$data['currency_id'],
            uuid: $data['uuid'],
            name: $data['name'],
            profile_image: $data['profile_image'],
            website: $data['website'],
            address: $data['address'],
            zipcode: $data['zipcode'],
            email: $data['email'],
            language: $data['language'],
            timezone: $data['timezone'],
            phone: $data['phone'],
        );
    }
}
