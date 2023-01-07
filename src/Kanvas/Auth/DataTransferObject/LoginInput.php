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
     * @param string $email
     * @param string $password
     * @param string $ip
     */
    public function __construct(
        public string $email,
        public string $password,
        public string $ip,
    ) {
    }
}
