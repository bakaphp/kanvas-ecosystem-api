<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Fakes;

use Kanvas\Connectors\OpenCode\Runtime\ContainerRuntimeDriver;

/**
 * The real driver without the wait: a container that refuses to go is reported in milliseconds
 * rather than after the fifteen-second grace.
 */
class ImpatientContainerRuntimeDriver extends ContainerRuntimeDriver
{
    protected function pollDelaySeconds(): int
    {
        return 0;
    }
}
