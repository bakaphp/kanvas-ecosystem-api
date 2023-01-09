<?php

declare(strict_types=1);

namespace Kanvas\Auth\DataTransferObject;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Baka\Support\Random;
use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class RegisterInput extends Data
{
    /**
     * Construct function.
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $displayname
     * @param string $email
     * @param string $password
     * @param string|null $default_company
     */
    public function __construct(
        public string $firstname,
        public string $lastname,
        public string $displayname,
        public string $email,
        public string $password,
        public ?string $default_company = null,
        public ?int $roles_id = null
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
            firstname: $request->get('firstname') ?? '',
            lastname: $request->get('lastname') ?? '',
            displayname: $request->get('displayname') ?? Random::generateDisplayName($request->get('email')),
            email: $request->get('email'),
            password: Hash::make($request->get('password')),
            default_company: $request->get('default_company') ?? null,
        );
    }

    /**
     * Generaet new instance of DTO from array.
     *
     * @param array $request
     *
     * @return self
     */
    public static function fromArray(array $request) : self
    {
        return new self(
            firstname: $request['firstname'] ?? '',
            lastname: $request['lastname'] ?? '',
            displayname: $request['displayname'] ?? Random::generateDisplayName($request['email']),
            email: $request['email'],
            password: Hash::make($request['password']),
            default_company: $request['default_company'] ?? null,
            roles_id: $request['roles_id'] ?? null,
        );
    }
}
