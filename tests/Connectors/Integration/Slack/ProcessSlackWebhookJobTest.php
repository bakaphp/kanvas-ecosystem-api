<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

final class ProcessSlackWebhookJobTest extends TestCase
{
    private const string BOT_USER_ID = 'UBOT123';
    private const string SLACK_CHANNEL = 'C0001';
    private const string DM_CHANNEL = 'D0001';

    // Channel slugs are derived from the team id. A fixed one would let rows from an earlier run
    // (this suite has no DatabaseTransactions) answer this run's queries.
    private string $teamId;
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private Agent $agent;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = 'T' . strtoupper(Str::random(6));
        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        // The outbound reply is persisted as the company's AI user.
        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());

        // Neuron stub — FakeNeuronProvider answers "Hola Mundo" without touching an LLM.
        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $this->agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'user_id' => $this->user->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);

        $this->agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test-token');

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackWebhookJob::class],
            ['name' => 'ProcessSlackWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    ConfigurationEnum::AGENT_ID->value => $this->agent->getId(),
                    ConfigurationEnum::BOT_USER_ID->value => self::BOT_USER_ID,
                    ConfigurationEnum::SIGNING_SECRET->value => 'shhh',
                ],
            ]);
    }

    public function testDirectMessageIsAnsweredByTheAgentAndBoundToThatUser(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent([
            'channel' => self::DM_CHANNEL,
            'channel_type' => 'im',
            'text' => 'what is my pipeline looking like',
        ]));

        $this->assertStringContainsString('Hola Mundo', $result['response']);

        $messages = $this->messagesOn(self::DM_CHANNEL);
        $inbound = $messages->first();
        $outbound = Message::getById((int) $result['message_id']);

        $this->assertSame('what is my pipeline looking like', $inbound->message['content']);
        // A DM is 1:1 — the conversation belongs to the human on the other side.
        $this->assertInstanceOf(Users::class, $inbound->entity());
        $this->assertSame($this->user->getId(), $inbound->entity()->getId());

        $this->assertTrue($outbound->message['from_ia']);
        $this->assertStringContainsString('Hola Mundo', $outbound->message['content']);
        $this->assertSlackReplyWasEdited();
        // A DM reads like a normal chat — the reply posts at the top level, not in a thread.
        $this->assertPlaceholderThreadTs(null);
    }

    public function testChannelMentionIsAnsweredAndBoundToTheChannel(): void
    {
        $this->fakeSlackApi();

        $this->dispatch($this->messageEvent([
            'type' => 'app_mention',
            'channel' => self::SLACK_CHANNEL,
            'text' => '<@' . self::BOT_USER_ID . '> status on the deal',
        ]));

        $inbound = $this->messagesOn(self::SLACK_CHANNEL)->first();

        // A room has many speakers — the conversation belongs to the room itself.
        $this->assertInstanceOf(Channel::class, $inbound->entity());
        $this->assertSame(
            'slack-' . strtolower($this->teamId . '-' . self::SLACK_CHANNEL),
            $inbound->entity()->slug
        );
        // Bot handle stripped; the speaker is attributed so the agent knows who's talking this turn.
        $speakerName = trim($this->user->firstname . ' ' . $this->user->lastname);
        $this->assertSame($speakerName . ': status on the deal', $inbound->message['content']);
        $this->assertSlackReplyWasEdited();
        // A top-level mention → reply in the main channel, not forced into a thread.
        $this->assertPlaceholderThreadTs(null);
    }

    public function testAMentionInsideAThreadIsAnsweredInThatThread(): void
    {
        $this->fakeSlackApi();

        $this->dispatch($this->messageEvent([
            'type' => 'app_mention',
            'channel' => self::SLACK_CHANNEL,
            'thread_ts' => '1699999999.000001',
            'text' => '<@' . self::BOT_USER_ID . '> and this?',
        ]));

        // The human is already in a thread — stay in it rather than replying to the channel.
        $this->assertPlaceholderThreadTs('1699999999.000001');
    }

    public function testDirectMessageFromUnknownSlackUserIsRejected(): void
    {
        $this->fakeSlackApi(email: 'nobody@nowhere.test');

        $result = $this->dispatch($this->messageEvent([
            'channel' => 'DSTRANGER',
            'channel_type' => 'im',
        ]));

        $this->assertSame('Sender has no Kanvas account', $result['message']);
        $this->assertNull(
            Channel::where('slug', 'slack-' . strtolower($this->teamId . '-dstranger'))->first(),
            'An unrecognized Slack user must not open a conversation.'
        );
    }

    public function testDuplicateEventIsProcessedOnlyOnce(): void
    {
        $this->fakeSlackApi();

        $event = $this->messageEvent(['channel' => self::DM_CHANNEL, 'channel_type' => 'im']);
        $eventId = 'Ev' . Str::random(8);

        $this->dispatch($event, $eventId);
        $result = $this->dispatch($event, $eventId);

        $this->assertSame('Duplicate event', $result['message']);
    }

    public function testOurOwnReplyIsNotTreatedAsInbound(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent([
            'channel' => self::SLACK_CHANNEL,
            'user' => self::BOT_USER_ID,
            'bot_id' => 'B0001',
        ]));

        $this->assertSame('Bot message ignored', $result['message']);
    }

    public function testChannelMessageWithoutMentionIsIgnored(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent([
            'channel' => self::SLACK_CHANNEL,
            'text' => 'just two humans talking',
        ]));

        $this->assertSame('Event not addressed to the agent', $result['message']);
    }

    public function testUninstallDeactivatesTheReceiver(): void
    {
        $result = $this->dispatch(['type' => 'app_uninstalled']);

        $this->assertSame('Slack app uninstalled, receiver deactivated', $result['message']);
        $this->assertFalse((bool) $this->receiver->refresh()->is_active);
    }

    private function fakeSlackApi(?string $email = null): void
    {
        Http::fake([
            'slack.com/api/users.info' => Http::response([
                'ok' => true,
                'user' => ['profile' => ['email' => $email ?? $this->user->email]],
            ]),
            'slack.com/api/*' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);
    }

    /**
     * The agent's answer replaces the "working on it…" placeholder rather than posting twice.
     */
    private function assertSlackReplyWasEdited(): void
    {
        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.update')
            && str_contains((string) $request->body(), 'Hola'));
    }

    private function assertPlaceholderThreadTs(?string $expected): void
    {
        Http::assertSent(function ($request) use ($expected): bool {
            if (! str_contains($request->url(), 'chat.postMessage')) {
                return false;
            }

            return ($request['thread_ts'] ?? null) === $expected;
        });
    }

    private function messagesOn(string $slackChannelId): Collection
    {
        $channel = Channel::where(
            'slug',
            'slack-' . strtolower($this->teamId . '-' . $slackChannelId)
        )->firstOrFail();

        return Message::whereHas('channels', fn ($query) => $query->where('channels.id', $channel->getId()))
            ->orderBy('id')
            ->get();
    }

    private function messageEvent(array $overrides = []): array
    {
        return [
            'type' => 'message',
            'user' => 'U0001',
            'channel' => self::SLACK_CHANNEL,
            'text' => 'hello',
            'ts' => '1700000000.000100',
            ...$overrides,
        ];
    }

    private function dispatch(array $event, ?string $eventId = null): array
    {
        $payload = [
            'type' => 'event_callback',
            'team_id' => $this->teamId,
            'api_app_id' => 'A0001',
            'event_id' => $eventId ?? 'Ev' . Str::random(8),
            'event' => $event,
        ];

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        $result = new ProcessSlackWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            // ProcessWebhookJob swallows throwables into Sentry and returns null. Without this the
            // failure surfaces as an unrelated TypeError and the real message is lost.
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }
}
