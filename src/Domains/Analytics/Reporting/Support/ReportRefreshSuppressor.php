<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Support;

use Closure;

/**
 * Turns off per-row report refresh for the duration of a bulk operation.
 *
 * Without this, an import that saves 26,000 people dispatches 26,000 refresh jobs — each of
 * which re-reads the same organizations and rebuilds rows that the next save invalidates again.
 * A bulk path suppresses the per-row dispatch and rebuilds once at the end instead.
 *
 * Deliberately not a config flag or a container binding: it has to be impossible to leave on by
 * accident. `while()` restores the previous state in a `finally`, so an exception inside the
 * callback cannot leave refresh disabled for the rest of a long-running Octane worker.
 */
class ReportRefreshSuppressor
{
    private static bool $suppressed = false;

    /**
     * Run $callback with per-row refresh suppressed, then restore.
     *
     * Nesting is safe: the previous value is restored rather than hardcoding false, so an inner
     * call cannot re-enable dispatch for an outer bulk operation.
     */
    public static function while(Closure $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed;
    }

    /**
     * Escape hatch for a worker that crashed mid-bulk. Nothing in normal operation calls this.
     */
    public static function reset(): void
    {
        self::$suppressed = false;
    }
}
