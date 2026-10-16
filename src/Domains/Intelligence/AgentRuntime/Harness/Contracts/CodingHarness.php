<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Contracts;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

interface CodingHarness extends AgentHarness
{
    public function diff(AgentTaskSession $session): HarnessDiff;

    /** The unified diff itself, for a human to read. */
    public function patch(AgentTaskSession $session): string;

    /**
     * Read-only inspection for a supervisor debugging a run. Never a write path — the agent owns the
     * workspace while it holds the session.
     */
    public function readFile(AgentTaskSession $session, string $path): string;
}
