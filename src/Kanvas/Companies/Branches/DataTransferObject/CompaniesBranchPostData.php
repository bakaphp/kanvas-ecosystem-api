<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\DataTransferObject;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kanvas\Contracts\DataTransferObject\BaseDataTransferObject;

/**
 * CompaniesBranchPostData class.
 */
class CompaniesBranchPostData extends BaseDataTransferObject
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
        public int $companies_id,
        public int $users_id,
        public int $is_default = 0,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $zipcode = null,
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
            name: $request->get('name'),
            companies_id: (int) $request->get('companies_id'),
            users_id: Auth::user()->id,
            is_default: (int) $request->get('is_default'),
            email : $request->get('email')
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
            users_id : $data['users_id'],
            companies_id : (int) $data['companies_id'],
            is_default : (int) $data['is_default'],
            email : $data['email'],
        );
    }
}
