<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

/**
 * A tool boundary that applies for the duration of one turn.
 *
 * Shared by the worker and verifier policies, which differ only in what they permit — the machinery
 * for turning a boundary on and reliably off is identical and worth having in one place.
 *
 * Scope is process-local. Queue workers are long-running, so the reset lives in a `finally` rather
 * than at the end of the callable: a leaked policy would silently strip tools from every later job in
 * that worker, which fails as "the agent forgot how to do its job" rather than as an error.
 *
 * Subclasses keep their own `$active` — a static on the parent would be shared across children, so
 * entering the worker policy would silently also enter the verifier's.
 */
abstract class ScopedToolPolicy
{
    abstract public static function isActive(): bool;

    abstract public static function permits(string $toolName): bool;

    abstract protected static function setActive(bool $active): void;

    /**
     * Run something under this boundary.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function within(callable $work): mixed
    {
        $previous = static::isActive();
        static::setActive(true);

        try {
            return $work();
        } finally {
            static::setActive($previous);
        }
    }
}
