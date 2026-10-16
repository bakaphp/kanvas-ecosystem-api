<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Agent\AgentState;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\WorkflowStatus;
use Override;

/** An agent whose thread another run holds for the whole wait: every chat() is refused with a fresh lease. */
class BusyThreadNeuronAgentStub extends SalesNeuronAgentStub
{
    #[Override]
    public function chat(mixed $messages = [], bool $stream = false): AgentState
    {
        throw new RunInFlightException(
            workflowId: 'thread-busy',
            runId: 'run-busy',
            status: WorkflowStatus::Running,
            executionAttempt: 1,
            leaseExpiresAt: time() + 3600,
        );
    }
}
