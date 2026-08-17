<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Traits;

use Throwable;

/**
 * Side effects that must never fail the run.
 *
 * Attaching artifacts, posting narration, recording spend, archiving a superseded session — the work
 * itself already succeeded and its result is what matters, so a failure here is reported and
 * swallowed rather than thrown at a caller who can do nothing about it.
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
