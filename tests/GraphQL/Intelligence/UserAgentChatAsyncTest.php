<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Helpers\AgentChatBroadcastChannel;
use Kanvas\Intelligence\Agents\Jobs\ProcessAgentChatTurnJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\FakeAgentHandler;
use Tests\TestCase;

/** Async exists because an inline turn is capped at 120s and a fan-out turn 504s through it. */
class UserAgentChatAsyncTest extends TestCase
{
    use DatabaseTransactions;

    protected Agent $agent;
    protected Apps $currentApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($this->currentApp->getId())
            ->create(['handler' => FakeAgentHandler::class]);

        $this->agent = Agent::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        // Also cleared on the way IN: a run killed mid-flight never reaches tearDown, and the
        // leftover setting silently flips the next run's expectations.
        $this->currentApp->del(AppSettingsEnums::AGENT_CHAT_ASYNC->getValue());
    }

    /**
     * DatabaseTransactions does NOT roll back app settings (HashTableTrait, own connection). Leaving
     * this set flips every later chat test to the queue — it reddened the whole userChat suite once.
     */
    protected function tearDown(): void
    {
        $this->currentApp->del(AppSettingsEnums::AGENT_CHAT_ASYNC->getValue());

        parent::tearDown();
    }

    private function enableAppWideAsync(): void
    {
        $this->currentApp->set(AppSettingsEnums::AGENT_CHAT_ASYNC->getValue(), true);

        // App settings round-trip through Redis, which DatabaseTransactions does not manage — assert
        // the write landed so a stale cache fails as "setting did not stick" rather than as a
        // misleading "expected pending, got completed" against the code under test.
        $this->assertTrue(
            (bool) $this->currentApp->get(AppSettingsEnums::AGENT_CHAT_ASYNC->getValue()),
            'agent_chat_async did not persist; the assertions below would be meaningless',
        );
    }

    public function testAsyncInputQueuesTheTurnAndReturnsPending(): void
    {
        Queue::fake();

        $response = $this->chat(['async' => true, 'message' => 'Cross-reference this list']);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json(), 'GraphQL errors: ' . $response->getContent());

        $this->assertSame('pending', $response->json('data.aiAgentUserChat.status'));
        $this->assertSame('', $response->json('data.aiAgentUserChat.response'));
        $this->assertNull($response->json('data.aiAgentUserChat.message'));

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $this->assertNotEmpty($sessionId, 'The session must exist up front so the client can subscribe to it');

        $this->assertSame(
            AgentChatBroadcastChannel::nameFor($this->agent, $sessionId),
            $response->json('data.aiAgentUserChat.broadcast_channel'),
            'The client must not have to assemble the channel name itself',
        );

        Queue::assertPushed(
            ProcessAgentChatTurnJob::class,
            fn (ProcessAgentChatTurnJob $job): bool => $job->session->uuid === $sessionId
                && $job->agent->getId() === $this->agent->getId()
                && $job->app->getId() === $this->currentApp->getId()
                && str_contains($job->message, 'Cross-reference this list')
                && $job->queue === 'agent-chat',
        );
    }

    /** The channel is created by the first turn, so only a brand-new session has none to return. */
    public function testPendingTurnOnAnEstablishedSessionStillCarriesTheChannel(): void
    {
        $seed = $this->chat(['message' => 'Turn one, inline']);
        $seed->assertSuccessful();
        $sessionId = $seed->json('data.aiAgentUserChat.session_id');

        Queue::fake();

        $pending = $this->chat([
            'message' => 'Turn two, queued',
            'session_id' => $sessionId,
            'async' => true,
        ]);

        $pending->assertSuccessful();
        $this->assertSame('pending', $pending->json('data.aiAgentUserChat.status'));
        $this->assertNull($pending->json('data.aiAgentUserChat.message'));
        $this->assertSame(
            $seed->json('data.aiAgentUserChat.channel.id'),
            $pending->json('data.aiAgentUserChat.channel.id'),
        );
    }

    public function testSyncIsTheDefaultAndStillReturnsTheReplyInline(): void
    {
        Queue::fake();

        $response = $this->chat(['message' => 'Answer me now']);

        $response->assertSuccessful();
        $this->assertSame('completed', $response->json('data.aiAgentUserChat.status'));
        $this->assertNotEmpty($response->json('data.aiAgentUserChat.response'));
        $this->assertNotNull($response->json('data.aiAgentUserChat.message'));
        $this->assertNotNull($response->json('data.aiAgentUserChat.channel'));

        Queue::assertNotPushed(ProcessAgentChatTurnJob::class);
    }

    public function testAppSettingMakesEveryChatAsync(): void
    {
        Queue::fake();
        $this->enableAppWideAsync();

        $response = $this->chat(['message' => 'App-wide async']);

        $response->assertSuccessful();
        $this->assertSame('pending', $response->json('data.aiAgentUserChat.status'));
        Queue::assertPushed(ProcessAgentChatTurnJob::class);
    }

    public function testExplicitAsyncFalseOverridesTheAppSetting(): void
    {
        Queue::fake();
        $this->enableAppWideAsync();

        $response = $this->chat(['message' => 'Opt this one back out', 'async' => false]);

        $response->assertSuccessful();
        $this->assertSame('completed', $response->json('data.aiAgentUserChat.status'));
        Queue::assertNotPushed(ProcessAgentChatTurnJob::class);
    }

    public function testQueuedJobRunsTheTurnAndPersistsItToTheSessionChannel(): void
    {
        $seed = $this->chat(['message' => 'Turn one, inline']);
        $seed->assertSuccessful();

        $sessionId = $seed->json('data.aiAgentUserChat.session_id');
        $session = Session::where('uuid', $sessionId)->firstOrFail();
        $channel = Channel::find($session->channel_id);
        $this->assertCount(2, $channel->messages()->get());

        new ProcessAgentChatTurnJob(
            app: $this->currentApp,
            agent: $this->agent,
            session: $session,
            user: auth()->user(),
            message: 'Turn two, queued',
        )->handle();

        $this->assertCount(
            4,
            $channel->messages()->get(),
            'The queued turn must persist its prompt + reply on the same channel as the inline one',
        );

        $contents = $channel->messages()
            ->orderBy('messages.id')
            ->get()
            ->map(fn ($message): string => (string) ($message->getMessage()['content'] ?? ''))
            ->all();

        $this->assertContains('Turn two, queued', $contents);
    }

    /** A reply too big to ride along is the normal case for fan-out answers, not an edge case. */
    public function testBroadcastCarriesAMessageIdEvenWhenTheResponseIsTooBigToShip(): void
    {
        Event::fake([AgentChatResponseEvent::class]);

        $response = $this->chat(['message' => 'Give me the table']);
        $response->assertSuccessful();

        $sessionId = $response->json('data.aiAgentUserChat.session_id');
        $expectedMessageId = (int) $response->json('data.aiAgentUserChat.message.id');

        Event::assertDispatched(
            AgentChatResponseEvent::class,
            function (AgentChatResponseEvent $event) use ($sessionId, $expectedMessageId): bool {
                $payload = $event->broadcastWith();

                return $payload['session_id'] === $sessionId
                    && $payload['message_id'] === $expectedMessageId;
            },
        );
    }

    public function testOversizedResponseIsDroppedButTheMessageIdSurvives(): void
    {
        $agent = $this->agent;
        $reply = new Message();
        $reply->id = 4242;

        $payload = new AgentChatResponseEvent(
            $agent,
            'session-uuid',
            str_repeat('user prompt ', 800),
            str_repeat('a very long tabular answer ', 800),
            $reply,
        )->broadcastWith();

        $this->assertNull($payload['response'], 'An oversized response is nulled, never truncated');
        $this->assertSame(4242, $payload['message_id'], 'The recovery handle must survive the cap');
    }

    public function testQueuedJobIsNeverRetried(): void
    {
        $session = Session::query()->firstOrNew(['uuid' => 'unused']);

        $job = new ProcessAgentChatTurnJob(
            app: $this->currentApp,
            agent: $this->agent,
            session: $session,
            user: new Users(),
            message: 'irrelevant',
        );

        $this->assertSame(
            1,
            $job->tries,
            'A retried turn would replay every tool side effect (leads created, emails sent)',
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function chat(array $input): TestResponse
    {
        return $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) {
                    response
                    session_id
                    status
                    broadcast_channel
                    message { id }
                    channel { id }
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                ...$input,
            ],
        ]);
    }
}
