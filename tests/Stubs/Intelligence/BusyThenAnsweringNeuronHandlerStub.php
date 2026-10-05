<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Workflow\WorkflowStatus;

/**
 * A handler whose thread is held by another run for the first N chat() calls, the way Neuron answers
 * a second inbound while a durable run still holds its lease, and then answers.
 */
class BusyThenAnsweringNeuronHandlerStub
{
    public int $calls = 0;

    public function __construct(private readonly int $busyCalls, private readonly string $reply = 'answered')
    {
    }

    public function chat(mixed $messages = []): AgentState
    {
        $this->calls++;

        if ($this->calls <= $this->busyCalls) {
            throw new RunInFlightException(
                workflowId: 'thread-1',
                runId: 'run-1',
                status: WorkflowStatus::Running,
                executionAttempt: 1,
                leaseExpiresAt: time() + 600,
            );
        }

        return new AgentState()->setResponse(new ProviderResponse(message: new AssistantMessage($this->reply)));
    }
}
