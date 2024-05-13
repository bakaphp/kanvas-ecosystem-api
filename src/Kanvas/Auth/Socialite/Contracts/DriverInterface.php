<?php

namespace Kanvas\Auth\Socialite\Contracts;

use Kanvas\Auth\Socialite\DataTransferObject\User;

interface DriverInterface
{
    public function __construct(array $config);

    public function getUserFromToken(string $token): User;
}
