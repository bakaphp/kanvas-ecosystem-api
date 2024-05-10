<?php

namespace Baka\Social\Contracts;

use Baka\Social\DataTransferObject\User;

interface DriverInterface
{
    public function __construct(array $config);

    public function getUserFromToken(string $token): User;
}
