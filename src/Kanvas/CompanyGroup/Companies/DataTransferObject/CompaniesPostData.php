<?php

declare(strict_types=1);

namespace Kanvas\CompanyGroup\Companies\DataTransferObject;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kanvas\Contracts\DataTransferObject\BaseDataTransferObject;

/**
 * CompaniesPostData class.
 */
class CompaniesPostData extends BaseDataTransferObject
{
    /**
     * Construct function.
     *
     * @param string $name
     * @param int|null $users_id
     * @param array|null $files
     */
    public function __construct(
        public string $name,
        public int $users_id,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $currency_id = null,
        public ?string $website = null,
        public ?string $address = null,
        public ?string $zipcode = null,
        public ?string $language = null,
        public ?string $timezone = null,
        public ?string $country_code = null,
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
            users_id: Auth::user()->id,
            name: $request->get('name'),
        );
    }

    /**
     * Create new instance of DTO from Console Command.
     *
     * @param array $data Input data
     *
     * @return self
     */
    public static function fromConsole(array $data) : self
    {
        return new self(
            name: $data['name'],
            users_id : $data['users_id']
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
            users_id : $data['users_id']
        );
    }
}
