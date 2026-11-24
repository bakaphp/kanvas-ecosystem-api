<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;
use RuntimeException;
use Tests\Stubs\Intelligence\DurableNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\MakesLeadConversations;

/**
 * A lead's rollup store leaves the prompt to whoever filed it. A scheduled wake is a prompt nobody
 * filed, so the worker that resumes its dead run reads back the lead's messages alone and the request
 * ends on the agent's previous reply, which Gemini refuses (KANVAS-ECOSYSTEM-6TX, 6QX).
 */
final class DurableResumeOnRollupStoreTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;
    use MakesLeadConversations;

    private const string TASK = 'Scheduled task: post the weekly report.';

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    private Agent $agentRecord;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        DurableNeuronAgentStub::reset();
        $this->agentRecord = $this->makeAgentFor(auth()->user());
    }

    public function testAWakeNobodyFiledStartsOverInsteadOfResumingWithoutItsPrompt(): void
    {
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'What is my balance?');
        $this->leadMessage($lead, 'Your balance is zero.', fromAgent: true);

        DurableNeuronAgentStub::$dieOnNextReply = true;

        try {
            $this->agent($lead)->chat(new UserMessage(self::TASK));
            $this->fail('The first firing must die after its tool round');
        } catch (RuntimeException $e) {
            $this->assertSame('provider died mid-turn', $e->getMessage());
        }

        DurableNeuronAgentStub::$requests = [];
        $nextFiring = $this->agent($lead);
        $state = $nextFiring->recoverInterruptedRun(new UserMessage(self::TASK))
            ?? $nextFiring->chat(new UserMessage(self::TASK));

        $this->assertNotEmpty(DurableNeuronAgentStub::$requests);

        foreach (DurableNeuronAgentStub::$requests as $request) {
            $text = array_map(static fn (Message $message): string => (string) $message->getContent(), $request);

            $this->assertNotSame(
                MessageRole::ASSISTANT->value,
                end($request)->getRole(),
                'A request that ends on a model turn is refused by the provider',
            );
            $this->assertStringContainsString(
                self::TASK,
                implode("\n", $text),
                'The model is told what it was woken for',
            );
        }

        $this->assertSame('Done: the item was created once.', $state->getMessage()?->getContent());
        $this->assertSame(
            2,
            DurableNeuronAgentStub::$writes,
            'Starting over repeats the tool the dead worker already ran',
        );
    }

    private function agent(Lead $lead): DurableNeuronAgentStub
    {
        $agent = new class () extends DurableNeuronAgentStub {
            #[Override]
            protected function messageStore(): MessageStoreInterface
            {
                return $this->entityRollupStore(sessionThreadId: null, includeInternal: true);
            }
        };
        $agent->setConfiguration(agent: $this->agentRecord, entity: $lead, user: auth()->user());
        $agent->setThreadId($lead->uuid);
        $agent->setPrivateUserTurn(true);

        return $agent;
    }
}
