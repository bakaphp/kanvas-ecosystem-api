<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Stubs\Intelligence\DurableNeuronAgentStub;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * DurableAgentRunTest proves the resume on a store that keeps whole messages. The transcript the
 * durable agents really use reloads text only, so the worker that resumes has to be handed the tool
 * round the dead one ran, or it answers a conversation that ends on the model's own words
 * (KANVAS-ECOSYSTEM-6QX, refused by Claude as a prefill) or runs the tool a second time.
 */
class DurableResumeOnConversationStoreTest extends TestCase
{
    use MakesAgents;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'crm'];

    private Agent $agentRecord;

    private People $people;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        DurableNeuronAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $this->agentRecord = $this->makeAgentFor($user);
        $this->people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'durable-resume-' . $this->agentRecord->getId(),
            ],
            [
                'name' => 'Durable resume',
                'description' => 'test',
                'users_id' => $user->getId(),
            ]
        );

        $this->session = Session::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => People::class,
            'entity_id' => $this->people->getId(),
            'uuid' => (string) Str::uuid(),
            'user' => [],
            'content' => [],
            'is_deleted' => 0,
        ]);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function toolCallLeadIns(): array
    {
        return [
            'the model spoke before calling the tool' => ['Let me check that.'],
            'the model called the tool in silence' => [null],
        ];
    }

    #[DataProvider('toolCallLeadIns')]
    public function testAResumedTurnIsHandedTheToolRoundTheDeadWorkerRan(?string $leadIn): void
    {
        DurableNeuronAgentStub::$toolCallLeadIn = $leadIn;
        DurableNeuronAgentStub::$dieOnNextReply = true;

        try {
            $this->agent()->chat(new UserMessage('Create the item, please.'));
            $this->fail('The first attempt must die with the provider');
        } catch (RuntimeException $e) {
            $this->assertSame('provider died mid-turn', $e->getMessage());
        }

        $state = $this->agent()->recoverInterruptedRun(new UserMessage('Create the item, please.'));

        $this->assertSame('Done: the item was created once.', $state?->getMessage()?->getContent());
        $this->assertSame(1, DurableNeuronAgentStub::$writes, 'The resumed model saw the result');

        $resumed = FakeNeuronProvider::$lastMessages;
        $this->assertSame(
            [UserMessage::class, ToolCallMessage::class, ToolResultMessage::class],
            array_map(static fn (Message $message): string => $message::class, $resumed),
        );
        $this->assertSame((string) $leadIn, (string) $resumed[1]->getContent());
        $this->assertSame('call-1', $resumed[1]->getToolCalls()[0]->getCallId());
        $this->assertSame(['item' => 1], $resumed[1]->getToolCalls()[0]->getInputs());
        $this->assertSame('call-1', $resumed[2]->getToolCalls()[0]->getCallId());
        $this->assertSame('created', $resumed[2]->getToolCalls()[0]->getResult());
        $this->assertSame(
            DurableNeuronAgentStub::THOUGHT_SIGNATURE,
            $resumed[1]->getMetadata('thought_signature'),
        );
    }

    /**
     * Outside a resume the transcript still reads back as text: the next turn must not be handed a
     * finished turn's tool rounds, only what was said.
     */
    public function testAFinishedTurnReloadsAsTextOnTheNextTurn(): void
    {
        DurableNeuronAgentStub::$toolCallLeadIn = 'Let me check that.';

        $this->agent()->chat(new UserMessage('Create the item, please.'));
        $this->agent()->chat(new UserMessage('Thanks.'));

        // The last request is the first turn's history, then "Thanks." and its own tool round.
        $history = array_map(
            static fn (Message $message): string => $message::class,
            array_slice(FakeNeuronProvider::$lastMessages, 0, -3),
        );

        $this->assertNotEmpty($history);
        $this->assertNotContains(ToolCallMessage::class, $history);
        $this->assertNotContains(ToolResultMessage::class, $history);
    }

    /**
     * A resume that fails falls through to a plain chat() on the same agent, and that new turn reads
     * the dead one as text like any other.
     */
    public function testAResumeThatFailsLeavesTheNextTurnReadingText(): void
    {
        $question = new UserMessage('Create the item, please.');
        DurableNeuronAgentStub::$toolCallLeadIn = 'Let me check that.';
        DurableNeuronAgentStub::$dieOnNextReply = true;
        $first = $this->agent();
        $this->dieOn(static fn () => $first->chat($question));

        $second = $this->agent();
        DurableNeuronAgentStub::$dieOnNextReply = true;
        $this->dieOn(static fn () => $second->recoverInterruptedRun(new UserMessage('Create the item, please.')));

        $second->chat(new UserMessage('Never mind, what time is it?'));

        $history = array_map(
            static fn (Message $message): string => $message::class,
            array_slice(FakeNeuronProvider::$lastMessages, 0, -3),
        );

        $this->assertNotEmpty($history);
        $this->assertNotContains(ToolCallMessage::class, $history);
        $this->assertNotContains(ToolResultMessage::class, $history);
    }

    private function dieOn(callable $step): void
    {
        try {
            $step();
            $this->fail('The provider must die on this step');
        } catch (RuntimeException $e) {
            $this->assertSame('provider died mid-turn', $e->getMessage());
        }
    }

    private function agent(): DurableNeuronAgentStub
    {
        $agent = new class () extends DurableNeuronAgentStub {
            #[Override]
            protected function messageStore(): MessageStoreInterface
            {
                return $this->conversationStore();
            }
        };
        $agent->setConfiguration(agent: $this->agentRecord, entity: $this->people, user: auth()->user());
        $agent->setSession($this->session);
        $agent->setThreadId($this->session->uuid);

        return $agent;
    }
}
