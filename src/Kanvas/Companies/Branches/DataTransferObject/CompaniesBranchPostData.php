<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\DataTransferObject;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        public ?array $address = null,
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
            name: $request->get('name'),
            companies_id: (int) $request->get('companies_id'),
            users_id: Auth::user()->id,
            is_default: (int) $request->get('is_default'),
            email : $request->get('email'),
            files : $request->get('files'),
            is_active: $data['is_active'] ?? true
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
            companies_id : (int) $data['companies_id'],
            is_default : (int) $data['is_default'],
            email : $data['email'] ?? null,
            phone : $data['phone'] ?? null,
            address : $data['address'] ?? null,
            zipcode : $data['zipcode'] ?? null,
            files: $data['files'] ?? null,
            is_active: $data['is_active'] ?? true
        );
    }
}
