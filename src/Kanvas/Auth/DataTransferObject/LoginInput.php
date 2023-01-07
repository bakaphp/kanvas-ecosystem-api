<?php

declare(strict_types=1);

namespace Kanvas\Auth\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * AppsData class.
 */
class LoginInput extends Data
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
        public string $email,
        public string $password,
        public string $ip,
    ) {
    }
}
