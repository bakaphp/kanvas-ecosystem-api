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
     * Construct.
     *
     */
    public function __construct(
        public string $email,
        public string $password,
        public string $ip,
        public ?string $deviceId = null
    ) {
    }

    /**
     * Get trim email.
     *
     */
    public function getEmail(): string
    {
        return ltrim(trim($this->email));
    }

    /**
     * Get trim password.
     *
     */
    public function getPassword(): string
    {
        return ltrim(trim($this->password));
    }
}
