<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use NeuronAI\Chat\Messages\UserMessage;
use RuntimeException;
use Tests\Stubs\Intelligence\DurableNeuronAgentStub;
use Tests\TestCase;

/**
 * The guarantee behind durable runs: a turn whose worker died after a tool wrote something resumes
 * from that point on the next worker, and the write is not repeated.
 */
class DurableAgentRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DurableNeuronAgentStub::reset();
    }

    public function testACrashedTurnResumesWithoutRepeatingItsWrite(): void
    {
        DurableNeuronAgentStub::$dieOnNextReply = true;
        $message = new UserMessage('Create the item, please.');

        try {
            $this->agent('thread-crash')->chat($message);
            $this->fail('The first attempt must die with the provider');
        } catch (RuntimeException $e) {
            $this->assertSame('provider died mid-turn', $e->getMessage());
        }

        $this->assertSame(1, DurableNeuronAgentStub::$writes, 'The tool ran before the crash');

        $state = $this->agent('thread-crash')->recoverInterruptedRun(new UserMessage('Create the item, please.'));

        $this->assertNotNull($state, 'A dead run for the same message is recovered');
        $this->assertSame('Done: the item was created once.', $state->getMessage()?->getContent());
        $this->assertSame(1, DurableNeuronAgentStub::$writes, 'Recovery replays the memoized tool result instead of writing again');
    }

    public function testADifferentMessageIsANewTurnNotARecovery(): void
    {
        DurableNeuronAgentStub::$dieOnNextReply = true;

        try {
            $this->agent('thread-other')->chat(new UserMessage('Create the item, please.'));
            $this->fail('The first attempt must die with the provider');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, DurableNeuronAgentStub::$writes);
        $this->assertNull($this->agent('thread-other')->recoverInterruptedRun(new UserMessage('Something unrelated')));
    }

    public function testAFinishedTurnLeavesNothingToRecover(): void
    {
        $this->agent('thread-done')->chat(new UserMessage('Create the item, please.'));

        $this->assertNull($this->agent('thread-done')->recoverInterruptedRun(new UserMessage('Create the item, please.')));
        $this->assertSame(1, DurableNeuronAgentStub::$writes);
    }

    private function agent(string $thread): DurableNeuronAgentStub
    {
        $agent = new DurableNeuronAgentStub();
        $agent->setThreadId($thread);

        return $agent;
    }
}
