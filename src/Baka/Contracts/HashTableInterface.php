<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface HashTableInterface
{
    /**
     * Marks a stored value as encrypted at rest. get() strips it and decrypts transparently,
     * so callers never see it. The version segment lets the envelope evolve without ambiguity.
     */
    public const SECRET_PREFIX = 'enc:kanvas:v1:';

    public function set(string $key, mixed $value, bool|int $isPublic = 0): bool;

    public function setEncrypted(string $key, mixed $value, bool|int $isPublic = 0): bool;

    public function get(string $key): mixed;

    public function isSecret(string $key): bool;

    public function del(string $key): bool;
}
