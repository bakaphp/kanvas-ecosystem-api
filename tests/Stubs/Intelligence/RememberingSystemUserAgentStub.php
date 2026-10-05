<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Tests\Stubs\Intelligence\Concerns\RemembersInProcess;

/**
 * An internal agent on the shared in-memory company store (see SharedCompanyMemory).
 */
class RememberingSystemUserAgentStub extends SystemUserAgent
{
    use RemembersInProcess;
}
