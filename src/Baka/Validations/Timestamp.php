<?php

declare(strict_types=1);

namespace Baka\Validations;

class Timestamp
{
    /**
     * Is valid timestamp.
     */
    public static function isValid(int|string|null $timestamp = null): bool
    {
        return ((string) (int) $timestamp === $timestamp)
        && ($timestamp <= PHP_INT_MAX)
        && ($timestamp >= ~PHP_INT_MAX);
    }
}
