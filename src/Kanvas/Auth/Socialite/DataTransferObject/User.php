<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite\DataTransferObject;

use Spatie\LaravelData\Data;

class User extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $nickname,
        public string $token
    ) {
    }
}
