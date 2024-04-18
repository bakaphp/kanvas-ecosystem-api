<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface HashTableInterface
{
    public function set(string $key, mixed $value, bool|int $isPublic = 0): bool;

    public function get(string $key): mixed;

    public function del(string $key): bool;
}
