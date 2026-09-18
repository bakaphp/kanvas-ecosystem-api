<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Jobs\ContinueAgentTurnJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The auto-continue for a turn that ran out of tool-output budget: who gets it, when it stops, and that
 * a person writing in the thread takes over from it.
 */
class ContinueAgentTurnJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    public function testAnInternalAgentCutShortIsContinued(): void
    {
        Bus::fake();

        ContinueAgentTurnJob::dispatchIfCutShort(
            $this->kernelThatRanOutOfBudget(SystemUserAgent::class, ['create_workflow:a']),
            '5 of 10 done',
        );

        Bus::assertDispatched(
            ContinueAgentTurnJob::class,
            fn (ContinueAgentTurnJob $job): bool => $job->continuation === 1
                && $job->previousReply === '5 of 10 done'
                && $job->executedCalls === ['create_workflow:a'],
        );
    }

    /** A stranger could otherwise turn one message into several paid turns. */
    public function testACustomerFacingAgentIsNeverContinued(): void
    {
        Bus::fake();

        ContinueAgentTurnJob::dispatchIfCutShort(
            $this->kernelThatRanOutOfBudget(SalesAgent::class, ['create_workflow:a']),
            '5 of 10 done',
        );

        Bus::assertNotDispatched(ContinueAgentTurnJob::class);
    }

    public function testATurnThatFinishedIsNotContinued(): void
    {
        Bus::fake();

        $kernel = $this->kernel(SystemUserAgent::class);
        $this->setNeuronRun($kernel, endedOnToolBudget: false, executedCalls: []);
        ContinueAgentTurnJob::dispatchIfCutShort($kernel, 'All 10 done');

        Bus::assertNotDispatched(ContinueAgentTurnJob::class);
    }

    public function testAContinuationThatMadeProgressQueuesTheNext(): void
    {
        Bus::fake();

        $this->followUp(continuation: 1, previousCalls: ['create_workflow:a'], nowRan: ['create_workflow:b']);

        Bus::assertDispatched(
            ContinueAgentTurnJob::class,
            fn (ContinueAgentTurnJob $job): bool => $job->continuation === 2
                && $job->executedCalls === ['create_workflow:a', 'create_workflow:b'],
        );
    }

    public function testTheCapStopsContinuing(): void
    {
        Bus::fake();

        $this->followUp(
            continuation: ContinueAgentTurnJob::MAX_CONTINUATIONS,
            previousCalls: ['create_workflow:a'],
            nowRan: ['create_workflow:b'],
        );

        Bus::assertNotDispatched(ContinueAgentTurnJob::class);
    }

    /** A turn that only repeated earlier calls has not moved the batch; the cap would just burn turns. */
    public function testAContinuationThatRepeatedOnlyOldCallsStops(): void
    {
        Bus::fake();

        $this->followUp(continuation: 1, previousCalls: ['create_workflow:a'], nowRan: ['create_workflow:a']);

        Bus::assertNotDispatched(ContinueAgentTurnJob::class);
    }

    public function testAPersonWritingAfterTheReplyStopsTheContinuation(): void
    {
        $channel = $this->channel();
        $since = now()->subMinute()->toIso8601String();

        $this->postMessage($channel, 'Agent progress report', ['from_ia' => true]);
        $this->assertFalse($this->personHasReplied($channel, $since), 'the agent\'s own messages are not a reply');

        $this->postMessage($channel, 'stop, I need to change the plan');
        $this->assertTrue($this->personHasReplied($channel, $since));
    }

    /** The message that started the request is older than the reply, so it must not count. */
    public function testAMessageFromBeforeTheReplyDoesNotStopIt(): void
    {
        $channel = $this->channel();
        $this->postMessage($channel, 'Set up the ten workflows');

        $this->assertFalse($this->personHasReplied($channel, now()->addMinute()->toIso8601String()));
    }

    /**
     * Nobody typed a private turn; whatever drove it delivers the reply. Broadcasting it too would show
     * the driving prompt as a chat message and put the reply on screen twice.
     */
    public function testAPrivateTurnIsNotBroadcast(): void
    {
        Event::fake([AgentChatResponseEvent::class]);

        $this->silentKernel(privateUserTurn: true)->execute();
        Event::assertNotDispatched(AgentChatResponseEvent::class);

        $this->silentKernel(privateUserTurn: false)->execute();
        Event::assertDispatched(AgentChatResponseEvent::class);
    }

    /**
     * @param list<string> $executedCalls
     */
    private function kernelThatRanOutOfBudget(string $handler, array $executedCalls): AgentChatKernel
    {
        $kernel = $this->kernel($handler);
        $this->setNeuronRun($kernel, endedOnToolBudget: true, executedCalls: $executedCalls);

        return $kernel;
    }

    private function kernel(string $handler): AgentChatKernel
    {
        return new AgentChatKernel(
            agent: $this->agentWithHandler($handler),
            session: new Session(),
            message: 'Set up the ten workflows',
            user: new Users(),
        );
    }

    /**
     * @param list<string> $executedCalls
     */
    private function setNeuronRun(AgentChatKernel $kernel, bool $endedOnToolBudget, array $executedCalls): void
    {
        $run = Mockery::mock(RunNeuronChatAction::class);
        $run->shouldReceive('endedOnToolBudget')->andReturn($endedOnToolBudget);
        $run->shouldReceive('executedToolCalls')->andReturn($executedCalls);

        new ReflectionProperty(AgentChatKernel::class, 'neuronRun')->setValue($kernel, $run);
    }

    /**
     * @param list<string> $previousCalls
     * @param list<string> $nowRan
     */
    private function followUp(int $continuation, array $previousCalls, array $nowRan): void
    {
        $job = new ContinueAgentTurnJob(
            agent: $this->agentWithHandler(SystemUserAgent::class),
            session: new Session(),
            user: new Users(),
            previousReply: 'progress',
            continuation: $continuation,
            executedCalls: $previousCalls,
            since: now()->toIso8601String(),
        );

        $kernel = Mockery::mock(AgentChatKernel::class);
        $kernel->shouldReceive('endedOnToolBudget')->andReturn(true);
        $kernel->shouldReceive('executedToolCalls')->andReturn($nowRan);

        new ReflectionMethod($job, 'continueIfStillCutShort')->invoke($job, $kernel, 'more progress');
    }

    private function agentWithHandler(string $handler): Agent
    {
        $type = new AgentType();
        $type->handler = $handler;

        $agent = new Agent();
        $agent->setRelation('type', $type);

        return $agent;
    }

    /**
     * A kernel whose turn runs no model and records no usage, so only the delivery behaviour is tested.
     */
    private function silentKernel(bool $privateUserTurn): AgentChatKernel
    {
        return new class (
            agent: new Agent(),
            session: null,
            message: 'continue',
            user: new Users(),
            privateUserTurn: $privateUserTurn,
        ) extends AgentChatKernel {
            protected function runHandler(): string
            {
                return 'done';
            }

            protected function trackUsage(string $response, float $durationMs, string $sessionId): void
            {
            }
        };
    }

    private function personHasReplied(Channel $channel, string $since): bool
    {
        $job = new ContinueAgentTurnJob(
            agent: new Agent(),
            session: new Session(),
            user: new Users(),
            previousReply: '',
            continuation: 1,
            executedCalls: [],
            since: $since,
        );

        return new ReflectionMethod($job, 'personHasReplied')->invoke($job, $channel);
    }

    private function channel(): Channel
    {
        /** @var Users $user */
        $user = auth()->user();
        $slug = 'continue-' . uniqid();

        return new CreateChannelAction(new ChannelData(
            apps: app(Apps::class),
            companies: $user->getCurrentCompany(),
            users: $user,
            entity_id: $user->getId(),
            entity_namespace: Users::class,
            name: $slug,
            slug: $slug,
            description: $slug,
        ))->execute();
    }

    /**
     * @param array<string, mixed> $extraPayload
     */
    private function postMessage(Channel $channel, string $content, array $extraPayload = []): void
    {
        new PostChannelMessageAction(
            channel: $channel,
            author: auth()->user(),
            verb: 'continue-test',
            content: $content,
            extraPayload: $extraPayload,
        )->execute();
    }
}
