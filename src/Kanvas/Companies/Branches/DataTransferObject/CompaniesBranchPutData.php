<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\DataTransferObject;

use Illuminate\Http\Request;
use Kanvas\Contracts\DataTransferObject\BaseDataTransferObject;

/**
 * CompaniesBranchPostData class.
 */
class CompaniesBranchPutData extends BaseDataTransferObject
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
        public int $is_default = 0,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?int $zipcode = null,
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
            name: $request->get('name'),
            companies_id: (int) $request->get('companies_id'),
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
            companies_id : (int) $data['companies_id'],
            is_default : (int) $data['is_default'],
            email : $data['email'] ?? null,
            phone : $data['phone'] ?? null,
            zipcode : $data['zipcode'] ?? null,
        );
    }
}
