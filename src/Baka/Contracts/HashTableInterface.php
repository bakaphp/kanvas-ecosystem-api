<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface HashTableInterface
{
    public const SECRET_PREFIX = 'enc:kanvas:v1:';

    public function set(string $key, mixed $value, bool|int $isPublic = 0): bool;

    public function setEncrypted(string $key, mixed $value): bool;

    public function get(string $key): mixed;

    public function getAll(bool $onlyPublicSettings = false, bool $publicFormat = false, bool $fromRedis = true): array;

    public function isSecret(string $key): bool;

    public static function isEncryptedValue(mixed $value): bool;

    public function del(string $key): bool;
}
