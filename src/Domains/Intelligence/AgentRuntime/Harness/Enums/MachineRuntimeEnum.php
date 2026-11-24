<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

/**
 * Which process runs opencode on a machine. A property of the machine, not the app: a Mac mini runs
 * it natively so jobs get Xcode and the Simulator, a Linux box runs it in a container.
 */
enum MachineRuntimeEnum: string
{
    /** One Docker container per agent — the default, and every machine before the column existed. */
    case CONTAINER = 'container';

    /** One host process per agent, run by the machine's own service manager. */
    case HOST = 'host';
}
