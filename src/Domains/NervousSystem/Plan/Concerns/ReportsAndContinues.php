<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Concerns;

use Throwable;

/**
 * For side effects that must never fail the run — attaching artifacts, recording spend, posting an
 * update, archiving a superseded session. The work itself already succeeded, so a failure here is
 * reported and swallowed rather than thrown at a caller who can do nothing about it.
 *
 * Lives beside Plan rather than in a connector: every async agent backend needs it, and the pi.dev
 * poller open-coded the same try/catch three times before this moved.
 */
trait ReportsAndContinues
{
    protected function bestEffort(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
