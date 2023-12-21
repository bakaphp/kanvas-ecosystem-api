<?php

declare(strict_types=1);

namespace Kanvas\Auth\DataTransferObject;

use Baka\Support\Random;
use Baka\Validations\PasswordValidation;
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
        public ?int $roles_id = null,
        public array $custom_fields = [],
        public ?CompaniesBranches $branch = null,
        public ?string $phone_number = null,
        public ?string $cell_phone_number = null,
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
            firstname: $request->get('firstname') ?? '',
            lastname: $request->get('lastname') ?? '',
            displayname: $request->get('displayname') ?? Random::generateDisplayName($request->get('email')),
            email: $request->get('email'),
            password: Hash::make($request->get('password')),
            default_company: $request->get('default_company') ?? null,
            roles_id: (int) ($request->get('roles_id') ?? null),
            custom_fields: $request->get('custom_fields') ?? []
        );
    }

    /**
     * Generate new instance of DTO from array.
     */
    public static function fromArray(array $request, ?CompaniesBranches $branch = null): self
    {
        //validate
        PasswordValidation::validateArray($request);

        return new self(
            firstname: $request['firstname'] ?? '',
            lastname: $request['lastname'] ?? '',
            displayname: $request['displayname'] ?? Random::generateDisplayName($request['email']),
            email: $request['email'],
            password: Hash::make($request['password']),
            default_company: $request['default_company'] ?? null,
            roles_id: isset($request['roles_id']) ? (int) $request['roles_id'] : null,
            custom_fields: $request['custom_fields'] ?? [],
            branch: $branch,
            phone_number: $request['phone_number'] ?? null,
            cell_phone_number: $request['cell_phone_number'] ?? null
        );
    }
}
