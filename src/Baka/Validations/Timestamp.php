<?php

declare(strict_types=1);

namespace Baka\Validations;

class Timestamp
{
    /**
     * Is valid timestamp.
     *
     * @param string|null $timestamp
     *
     * @return bool
     */
    public static function isValid(?int $timestamp = null): bool
    {
        return ((string) (int) $timestamp === $timestamp)
        && ($timestamp <= PHP_INT_MAX)
        && ($timestamp >= ~PHP_INT_MAX);
    }
}
