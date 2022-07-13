<?php

declare(strict_types=1);


namespace Kanvas\Users\Users\DataTransferObject;

use Spatie\DataTransferObject\DataTransferObject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * AppsData class
 */
class RegisterPostData extends DataTransferObject
{
    /**
     * Construct function
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $displayname
     * @param string $email
     * @param string $password
     * @param string $default_company
     */
    public function __construct(
        public string $firstname,
        public string $lastname,
        public string $displayname,
        public string $email,
        public string $password,
        public string $default_company,
    ) {
    }

    /**
     * Create new instance of DTO from request
     *
     * @param Request $request Request Input data
     *
     * @return self
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            firstname: $request->get('firstname'),
            lastname: $request->get('lastname'),
            displayname: $request->get('displayname'),
            email: $request->get('email'),
            password: Hash::make($request->get('password')),
            default_company: $request->get('default_company')
        );
    }
}
