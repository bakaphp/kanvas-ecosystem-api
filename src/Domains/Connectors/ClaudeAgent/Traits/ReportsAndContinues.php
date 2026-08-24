<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Traits;

use Throwable;

/**
 * For side effects that must never fail the run — attaching artifacts, recording spend, archiving a
 * superseded session. The work itself already succeeded, so a failure here is reported and swallowed
 * rather than thrown at a caller who can do nothing about it.
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
