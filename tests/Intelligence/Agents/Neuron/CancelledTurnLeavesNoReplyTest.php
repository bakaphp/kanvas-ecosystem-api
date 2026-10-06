<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Exceptions\AgentTurnCancelledException;
use Kanvas\Intelligence\Agents\Services\AgentTurnCancellationService;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\CapturingNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * The chat action is where a cancelled turn differs from a failed one: no fallback prose, no turn
 * logged, the exception reaches the caller, and the stop flag is gone so the resend runs.
 */
class CancelledTurnLeavesNoReplyTest extends TestCase
{
    use MakesAgents;

    public function testACancelledTurnPropagatesAndClearsTheStop(): void
    {
        $user = auth()->user();
        $agentRecord = $this->makeAgentFor($user);
        $thread = 'cancel-' . Str::uuid();

        $handler = new CapturingNeuronAgentStub();
        $handler->setConfiguration(agent: $agentRecord, user: $user);
        $handler->setThreadId($thread);
        $provider = new CapturingNeuronProvider();
        $handler->capturedProvider = $provider;

        AgentTurnCancellationService::request($thread);

        $action = new RunNeuronChatAction(
            agent: $agentRecord,
            session: null,
            message: 'Draft the whole proposal',
            app: app(Apps::class),
            user: $user,
            handler: $handler,
        );

        try {
            $action->execute();
            $this->fail('A cancelled turn is not answered with the fallback prose');
        } catch (AgentTurnCancelledException $e) {
            $this->assertSame($thread, $e->threadId);
        }

        $this->assertSame([], $provider->messages, 'No inference ran');
        $this->assertFalse(AgentTurnCancellationService::isRequested($thread), 'The stop is consumed, so the resend runs');
    }
}
