<?php

namespace Kanvas\Auth\Social\Contracts;

use Kanvas\Auth\Social\DataTransferObject\User;

interface DriverInterface
{
    public function __construct(array $config);

    public function getUserFromToken(string $token): User;
}
