<?php

declare(strict_types=1);

namespace Kanvas\Auth\DataTransferObject;

use Baka\Support\Random;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Kanvas\Companies\Models\CompaniesBranches;
use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class RegisterInput extends Data
{
    /**
     * Construct function.
     */
    public function __construct(
        public string $firstname,
        public string $lastname,
        public string $displayname,
        public string $email,
        public string $password,
        public ?string $default_company = null,
        public array $role_ids = [],
        public array $custom_fields = [],
        public ?CompaniesBranches $branch = null,
        public ?string $phone_number = null,
        public ?string $cell_phone_number = null,
        public ?string $raw_password = null
    ) {
    }

    /**
     * Create new instance of DTO from request.
     *
     * @param Request $request Request Input data
     */
    public static function viaRequest(Request $request): self
    {
        $roles = isset($request['role_id']) ? [$request['role_id']] : ($request['roles_id'] ?? []);

        return new self(
            firstname: $request->get('firstname') ?? '',
            lastname: $request->get('lastname') ?? '',
            displayname: ! empty($request->get('displayname')) ? Random::cleanUpDisplayNameForSlug($request->get('displayname')) : Random::generateDisplayName($request->get('email')),
            email: $request->get('email'),
            password: Hash::make($request->get('password')),
            default_company: $request->get('default_company') ?? null,
            role_ids: $roles,
            custom_fields: $request->get('custom_fields') ?? [],
            phone_number: $request->get('phone_number') ?? null,
            raw_password: $request->get('password') ?? null,
        );
    }

    /**
     * Generate new instance of DTO from array.
     */
    public static function fromArray(array $request, ?CompaniesBranches $branch = null): self
    {
        $roles = isset($request['role_id']) ? [$request['role_id']] : ($request['role_ids'] ?? []);

        return new self(
            firstname: $request['firstname'] ?? '',
            lastname: $request['lastname'] ?? '',
            displayname: ! empty($request['displayname']) ? Random::cleanUpDisplayNameForSlug($request['displayname']) : Random::generateDisplayName($request['email']),
            email: $request['email'],
            password: Hash::make($request['password']),
            default_company: $request['default_company'] ?? null,
            role_ids: $roles,
            custom_fields: $request['custom_fields'] ?? [],
            branch: $branch,
            phone_number: $request['phone_number'] ?? null,
            cell_phone_number: $request['cell_phone_number'] ?? null,
            raw_password: $request['password'] ?? null,
        );
    }
}
