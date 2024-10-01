<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\DataTransferObject;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * CompaniesBranchPostData class.
 */
class CompaniesBranchPutData extends Data
{
    /**
     * Construct function.
     *
     * @param int|null $users_id
     */
    public function __construct(
        public string $name,
        public int $companies_id,
        public ?int $is_default = null,
        public bool $is_active = true,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?int $zipcode = null,
        public ?array $files = null
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     */
    public static function viaRequest(Request $request): self
    {
        // send the same amount of fields as the other function or just leave one.
        return new self(
            name: $request->get('name'),
            companies_id: (int) $request->get('companies_id'),
            is_default: (int) $request->get('is_default') ?? null,
            email : $request->get('email'),
            files : $request->get('files'),
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
            companies_id : (int) $data['companies_id'],
            is_default : !empty($data['is_default']) ? (int) $data['is_default'] : null,
            email : $data['email'] ?? null,
            phone : $data['phone'] ?? null,
            address : $data['address'] ?? null,
            zipcode : $data['zipcode'] ?? null,
            files : $data['files'] ?? null,
        );
    }
}
