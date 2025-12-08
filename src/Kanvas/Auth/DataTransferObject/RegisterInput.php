<?php

declare(strict_types=1);

namespace Kanvas\Auth\DataTransferObject;

use Baka\Support\Random;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
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
    public static function viaRequest(Request $request, ?Apps $app = null): self
    {
        $app = $app ?? app(Apps::class);
        $roles = isset($request['role_id']) ? [$request['role_id']] : ($request['roles_id'] ?? []);

        return new self(
            firstname: $request->get('firstname') ?? '',
            lastname: $request->get('lastname') ?? '',
            displayname: ! empty($request->get('displayname')) ? Random::cleanUpDisplayNameForSlug($request->get('displayname')) : Random::generateDisplayNameFromEmail($request->get('email'), $app),
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
    public static function fromArray(array $request, ?CompaniesBranches $branch = null, ?Apps $app = null): self
    {
        $app = $app ?? app(Apps::class);
        $roles = isset($request['role_id']) ? [$request['role_id']] : ($request['role_ids'] ?? []);

        return new self(
            firstname: $request['firstname'] ?? '',
            lastname: $request['lastname'] ?? '',
            displayname: ! empty($request['displayname']) ? Random::cleanUpDisplayNameForSlug($request['displayname']) : Random::generateDisplayNameFromEmail($request['email'], $app),
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
