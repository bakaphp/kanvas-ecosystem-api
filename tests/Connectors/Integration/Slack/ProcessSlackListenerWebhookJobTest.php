<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Jobs\JoinChannelJob;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackListenerWebhookJob;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class ProcessSlackListenerWebhookJobTest extends TestCase
{
    private const string BOT_USER_ID = 'UBOT999';
    private const string SLACK_CHANNEL = 'C9001';
    private const string DENIED_CHANNEL = 'C9666';

    // Channel slugs derive from the team id. A fixed one would let rows from an earlier run (this
    // suite has no DatabaseTransactions) answer this run's queries.
    private string $teamId;
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = 'T' . strtoupper(Str::random(6));
        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackListenerWebhookJob::class],
            ['name' => 'ProcessSlackListenerWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    ConfigurationEnum::BOT_TOKEN->value => 'xoxb-test-token',
                    ConfigurationEnum::BOT_USER_ID->value => self::BOT_USER_ID,
                    ConfigurationEnum::SIGNING_SECRET->value => 'shhh',
                    ConfigurationEnum::CHANNEL_DENY_LIST->value => [self::DENIED_CHANNEL],
                ],
            ]);
    }

    public function testChannelMessageIsRecordedAndNothingIsPostedBack(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent(['text' => 'ship it on friday']));

        $this->assertSame('Ingested', $result['message']);

        $message = $this->messagesOn(self::SLACK_CHANNEL)->firstOrFail();
        $this->assertSame('ship it on friday', $message->message['content']);
        $this->assertSame(self::SLACK_CHANNEL, $message->message['slack_channel']);
        $this->assertSame($this->teamId, $message->message['slack_team']);

        // The whole point of the listener: it hears, it does not speak.
        $this->assertNoSlackWriteWasAttempted();
    }

    public function testIngestedMessageIsNotPublicAndIsBoundToTheChannel(): void
    {
        $this->fakeSlackApi();

        $this->dispatch($this->messageEvent(['text' => 'internal roadmap chatter']));

        $message = $this->messagesOn(self::SLACK_CHANNEL)->firstOrFail();

        // is_public would put a company's whole Slack history on the public Social feeds.
        $this->assertSame(0, (int) $message->is_public);
        // A room belongs to the workspace, not to any one speaker.
        $this->assertInstanceOf(Channel::class, $message->entity());
    }

    public function testMessageIsStillRecordedWhenTheSpeakerHasNoKanvasAccount(): void
    {
        // A speaker id of its own: SlackUserResolverService caches email by slack user id, so
        // reusing the shared one would just read whatever an earlier test resolved.
        $slackUserId = 'U' . strtoupper(Str::random(7));
        $this->fakeSlackApi(email: 'nobody-' . Str::random(6) . '@example.com');

        $result = $this->dispatch($this->messageEvent([
            'user' => $slackUserId,
            'text' => 'a contractor speaking',
        ]));

        $this->assertSame('Ingested', $result['message']);

        $message = $this->messagesOn(self::SLACK_CHANNEL)->firstOrFail();
        $this->assertFalse($message->message['slack_speaker_resolved']);
        // The raw Slack id survives so the row can be re-attributed once the person is invited.
        $this->assertSame($slackUserId, $message->message['slack_user']);
    }

    public function testTheChannelIsNamedAfterTheRealSlackChannel(): void
    {
        $this->fakeSlackApi(channelName: 'engineering', channelPurpose: 'Where the build happens');

        $this->dispatch($this->messageEvent(['text' => 'deploying now']));

        $channel = $this->channelFor(self::SLACK_CHANNEL);
        $this->assertSame('#engineering', $channel->name);
        $this->assertSame('Where the build happens', $channel->description);
    }

    /**
     * Rows created before the name was resolvable keep the raw id, and CreateChannelAction only
     * writes name/description on insert — so the ingest path has to heal them as traffic flows.
     */
    public function testAChannelStillNamedByRawIdIsHealedOnTheNextMessage(): void
    {
        $this->fakeSlackApi();
        $this->dispatch($this->messageEvent(['text' => 'first message']));

        $channel = $this->channelFor(self::SLACK_CHANNEL);
        $channel->name = 'Slack ' . self::SLACK_CHANNEL;
        $channel->description = 'Slack workspace conversation ' . self::SLACK_CHANNEL;
        $channel->saveOrFail();

        $this->forgetChannelCache(self::SLACK_CHANNEL);
        $this->fakeSlackApi(channelName: 'general');
        $this->dispatch($this->messageEvent(['text' => 'second message']));

        $this->assertSame('#general', $this->channelFor(self::SLACK_CHANNEL)->name);
    }

    /**
     * A rate-limited conversations.info must never stomp a good name back to the raw id.
     */
    public function testAFailedLookupLeavesTheExistingNameAlone(): void
    {
        $this->fakeSlackApi(channelName: 'general');
        $this->dispatch($this->messageEvent(['text' => 'first message']));

        $this->forgetChannelCache(self::SLACK_CHANNEL);
        Http::fake([
            'slack.com/api/conversations.info' => Http::response(['ok' => false, 'error' => 'ratelimited']),
            'slack.com/api/users.info' => Http::response([
                'ok' => true,
                'user' => ['profile' => ['email' => $this->user->email]],
            ]),
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);
        $this->dispatch($this->messageEvent(['text' => 'second message']));

        $this->assertSame('#general', $this->channelFor(self::SLACK_CHANNEL)->name);
    }

    /**
     * Slack sends a stub file object for anything the app can't read directly. It is an ordinary
     * outcome, so the message must still land and nothing may be thrown or reported.
     */
    public function testAFileSlackWontLetUsDownloadIsSkippedNotReported(): void
    {
        $this->fakeSlackApi();
        $this->receiver->configuration = [
            ...$this->receiver->configuration,
            ConfigurationEnum::INGEST_FILES->value => true,
        ];
        $this->receiver->saveOrFail();

        $result = $this->dispatch($this->messageEvent([
            'text' => 'here is the mockup',
            'files' => [[
                'id' => 'F0BSA6JLEA1',
                'filetype' => 'png',
                'file_access' => 'check_file_info',
                'user' => null,
            ]],
        ]));

        $this->assertSame('Ingested', $result['message']);
        $this->assertSame('here is the mockup', $this->messagesOn(self::SLACK_CHANNEL)->firstOrFail()->message['content']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'files.slack.com'));
    }

    public function testDuplicateEventIsIgnored(): void
    {
        $this->fakeSlackApi();

        $event = $this->messageEvent();
        $eventId = 'Ev' . Str::random(8);

        $this->dispatch($event, $eventId);
        $result = $this->dispatch($event, $eventId);

        $this->assertSame('Duplicate event', $result['message']);
        $this->assertCount(1, $this->messagesOn(self::SLACK_CHANNEL));
    }

    public function testOurOwnBotIsNotRecorded(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent([
            'user' => self::BOT_USER_ID,
            'bot_id' => 'B0001',
        ]));

        $this->assertSame('Bot message ignored', $result['message']);
    }

    public function testJoinAndLeaveNoiseIsNotRecorded(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent([
            'subtype' => 'channel_join',
            'text' => '<@U0001> has joined the channel',
        ]));

        $this->assertSame('Non-conversational subtype ignored', $result['message']);
        $this->assertCount(0, $this->messagesOn(self::SLACK_CHANNEL));
    }

    public function testDeniedChannelIsNeverRecorded(): void
    {
        $this->fakeSlackApi();

        $result = $this->dispatch($this->messageEvent([
            'channel' => self::DENIED_CHANNEL,
            'text' => 'salary review notes',
        ]));

        $this->assertSame('Channel is on the deny list', $result['message']);
        $this->assertCount(0, $this->messagesOn(self::DENIED_CHANNEL));
    }

    public function testEditIsStoredAsItsOwnTaggedRow(): void
    {
        $this->fakeSlackApi();

        $this->dispatch($this->messageEvent(['text' => 'ship it on friday']));

        $result = $this->dispatch([
            'type' => 'message',
            'subtype' => 'message_changed',
            'channel' => self::SLACK_CHANNEL,
            'message' => [
                'user' => 'U0001',
                'text' => 'ship it on monday',
                'ts' => '1700000000.000100',
            ],
        ]);

        $this->assertSame('Ingested', $result['message']);

        $messages = $this->messagesOn(self::SLACK_CHANNEL);
        $this->assertCount(2, $messages);
        $this->assertSame('ship it on monday', $messages->last()->message['content']);
        $this->assertTrue($messages->last()->tags->contains('name', 'slack-edit'));
    }

    public function testNewChannelQueuesAJoinSoCoverageDoesNotDecay(): void
    {
        Queue::fake();

        $result = $this->dispatch([
            'type' => 'channel_created',
            'channel' => ['id' => 'C9002', 'name' => 'new-room'],
        ]);

        $this->assertSame('Joining newly created channel', $result['message']);
        Queue::assertPushed(
            JoinChannelJob::class,
            fn (JoinChannelJob $job): bool => $job->slackChannelId === 'C9002'
        );
    }

    public function testTheFirehoseRunsOnItsOwnQueue(): void
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            ['type' => 'event_callback', 'team_id' => $this->teamId, 'event' => $this->messageEvent()]
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        // Receivers otherwise share the default queue. This one fires on every message in the
        // workspace, so a busy morning would sit in front of latency-sensitive agent replies.
        $this->assertSame('slack-ingest', new ProcessSlackListenerWebhookJob($webhookRequest)->queue);
    }

    public function testUninstallDeactivatesTheListener(): void
    {
        $result = $this->dispatch(['type' => 'app_uninstalled']);

        $this->assertSame('Slack app uninstalled, listener deactivated', $result['message']);
        $this->assertFalse((bool) $this->receiver->refresh()->is_active);
    }

    public function testUrlVerificationIsAnsweredInline(): void
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            ['type' => 'url_verification', 'challenge' => 'abc123']
        );

        $response = ProcessSlackListenerWebhookJob::handshakeResponse($request, $this->receiver);

        $this->assertSame(['challenge' => 'abc123'], $response);
    }

    private function fakeSlackApi(
        ?string $email = null,
        ?string $channelName = null,
        ?string $channelPurpose = null,
    ): void {
        Http::fake([
            'slack.com/api/users.info' => Http::response([
                'ok' => true,
                'user' => ['profile' => ['email' => $email ?? $this->user->email]],
            ]),
            'slack.com/api/conversations.info' => Http::response([
                'ok' => true,
                'channel' => [
                    'id' => self::SLACK_CHANNEL,
                    'name' => $channelName ?? 'general',
                    'purpose' => ['value' => $channelPurpose ?? ''],
                ],
            ]),
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * Targeted, never Cache::flush() — flushing wipes the cached Apps/config the test bootstrap
     * relies on, and every later test in the process dies on a null app id.
     */
    private function forgetChannelCache(string $slackChannelId): void
    {
        Cache::forget(
            'slack:channel-info:' . $this->kanvasApp->getId() . ':' . $this->company->getId()
            . ':' . $this->teamId . ':' . $slackChannelId
        );
    }

    private function channelFor(string $slackChannelId): Channel
    {
        return Channel::where(
            'slug',
            'slack-' . strtolower($this->teamId . '-' . $slackChannelId)
        )->firstOrFail();
    }

    /**
     * The listener's manifest has no chat:write, but a stray postMessage in the ingest path would
     * still be a bug on any workspace whose token was minted before this test existed.
     */
    private function assertNoSlackWriteWasAttempted(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat.postMessage'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat.update'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'files.'));
    }

    private function messagesOn(string $slackChannelId): Collection
    {
        $channel = Channel::where(
            'slug',
            'slack-' . strtolower($this->teamId . '-' . $slackChannelId)
        )->first();

        if ($channel === null) {
            return new Collection();
        }

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
            'channel_type' => 'channel',
            'text' => 'hello team',
            'ts' => '1700000000.000100',
            ...$overrides,
        ];
    }

    private function dispatch(array $event, ?string $eventId = null): array
    {
        $payload = [
            'type' => 'event_callback',
            'team_id' => $this->teamId,
            'api_app_id' => 'A9001',
            'event_id' => $eventId ?? 'Ev' . Str::random(8),
            'event' => $event,
        ];

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        $result = new ProcessSlackListenerWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            // ProcessWebhookJob swallows throwables into Sentry and returns null. Without this the
            // failure surfaces as an unrelated TypeError and the real message is lost.
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }
}
