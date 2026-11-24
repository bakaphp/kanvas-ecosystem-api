<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Fakes;

use Kanvas\Connectors\OpenCode\Runtime\HostProcessRuntimeDriver;

/**
 * The real driver without the wait, and with a readiness probe short enough that a job that never
 * answers fails in two attempts rather than the real budget.
 */
class ImpatientHostProcessRuntimeDriver extends HostProcessRuntimeDriver
{
    protected function pollDelaySeconds(): int
    {
        return 0;
    }

    protected function probeAttempts(): int
    {
        return 2;
    }
}
