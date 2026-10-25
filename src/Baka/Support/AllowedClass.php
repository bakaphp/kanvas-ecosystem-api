<?php

declare(strict_types=1);

namespace Baka\Support;

use Kanvas\Exceptions\ValidationException;
use ReflectionClass;

/**
 * A class name read from a database row or an input is only built when it implements the contract
 * its caller expects. `class_exists()` alone lets a stored string construct any class on the
 * autoloader, constructor side effects included.
 */
final class AllowedClass
{
    /**
     * @param class-string ...$contracts
     */
    public static function resolve(?string $class, string ...$contracts): ?string
    {
        if ($class === null || $class === '' || ! class_exists($class)) {
            return null;
        }

        if (new ReflectionClass($class)->isAbstract()) {
            return null;
        }

        foreach ($contracts as $contract) {
            if (is_a($class, $contract, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * For write paths: an empty value is allowed, a value that would not resolve is refused.
     *
     * @param class-string ...$contracts
     */
    public static function assert(?string $class, string $field, string ...$contracts): void
    {
        if ($class === null || $class === '') {
            return;
        }

        if (self::resolve($class, ...$contracts) === null) {
            throw new ValidationException(sprintf('%s is not an allowed %s.', $class, $field));
        }
    }
}
