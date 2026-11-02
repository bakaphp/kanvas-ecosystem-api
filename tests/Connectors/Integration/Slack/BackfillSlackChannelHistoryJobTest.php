<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Jobs\BackfillSlackChannelHistoryJob;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

final class BackfillSlackChannelHistoryJobTest extends TestCase
{
    private const string BOT_USER_ID = 'UBOT321';
    private const string ROOT_TS = '1700000100.000100';
    private const string REPLY_TS = '1700000200.000100';
    private const string LATER_TS = '1700000300.000100';

    private string $teamId;
    private string $slackChannelId;
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private Agent $agent;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = 'T' . strtoupper(Str::random(6));
        $this->slackChannelId = 'C' . strtoupper(Str::random(6));
        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

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
                    ConfigurationEnum::TEAM_ID->value => $this->teamId,
                    ConfigurationEnum::SIGNING_SECRET->value => 'shhh',
                ],
            ]);
    }

    public function testItPullsRootsAndTheirRepliesInConversationOrder(): void
    {
        $this->fakeSlackApi();

        new BackfillSlackChannelHistoryJob($this->receiver, $this->slackChannelId)->handle();

        $messages = $this->messagesOnChannel();
        $this->assertCount(3, $messages);

        $root = $messages->first(fn (Message $message) => $message->message['message_id'] === self::ROOT_TS);
        $reply = $messages->first(fn (Message $message) => $message->message['message_id'] === self::REPLY_TS);
        $later = $messages->first(fn (Message $message) => $message->message['message_id'] === self::LATER_TS);

        $this->assertNull($root->parent_id);
        $this->assertSame($root->getId(), (int) $reply->parent_id);
        $this->assertNull($later->parent_id);

        // Rows keep Slack's time, so the channel reads in the order people spoke.
        $this->assertSame((int) self::REPLY_TS, $reply->created_at->getTimestamp());
        $this->assertTrue($messages->every(fn (Message $message) => $message->tags->contains('name', BackfillSlackChannelHistoryJob::TAG)));
        $this->assertTrue($messages->every(fn (Message $message) => (int) $message->is_public === 0));

        // Written, never spoken to.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat.postMessage'));
    }

    public function testBotPostsAndJoinNoiseAreLeftOut(): void
    {
        $this->fakeSlackApi();

        new BackfillSlackChannelHistoryJob($this->receiver, $this->slackChannelId)->handle();

        $contents = $this->messagesOnChannel()->map(fn (Message $message) => $message->message['content']);

        $this->assertFalse($contents->contains(fn (string $content) => str_contains($content, 'I am a bot')));
        $this->assertFalse($contents->contains(fn (string $content) => str_contains($content, 'has joined')));
    }

    public function testARerunWritesNothingTwiceAndMarksTheRoomDone(): void
    {
        $this->fakeSlackApi();

        new BackfillSlackChannelHistoryJob($this->receiver, $this->slackChannelId)->handle();
        new BackfillSlackChannelHistoryJob($this->receiver, $this->slackChannelId)->handle();

        $this->assertCount(3, $this->messagesOnChannel());
        $this->assertContains(
            $this->slackChannelId,
            (array) ($this->receiver->refresh()->configuration[ConfigurationEnum::CHANNELS_BACKFILLED->value] ?? [])
        );
    }

    public function testANextPageIsRescheduledWithItsCursor(): void
    {
        Queue::fake();
        $this->fakeSlackApi(nextCursor: 'page-2');

        new BackfillSlackChannelHistoryJob($this->receiver, $this->slackChannelId)->handle();

        Queue::assertPushed(
            BackfillSlackChannelHistoryJob::class,
            fn (BackfillSlackChannelHistoryJob $job): bool => $job->cursor === 'page-2'
                && $job->imported === 3
                && $job->slackChannelId === $this->slackChannelId
        );
        // Not done until the last page.
        $this->assertNotContains(
            $this->slackChannelId,
            (array) ($this->receiver->refresh()->configuration[ConfigurationEnum::CHANNELS_BACKFILLED->value] ?? [])
        );
    }

    public function testARoomTheBotCannotReadIsSkippedQuietly(): void
    {
        Http::fake([
            'slack.com/api/conversations.history' => Http::response(['ok' => false, 'error' => 'not_in_channel']),
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);

        new BackfillSlackChannelHistoryJob($this->receiver, $this->slackChannelId)->handle();

        $this->assertCount(0, $this->messagesOnChannel());
        $this->assertContains(
            $this->slackChannelId,
            (array) ($this->receiver->refresh()->configuration[ConfigurationEnum::CHANNELS_BACKFILLED->value] ?? [])
        );
    }

    private function fakeSlackApi(string $nextCursor = ''): void
    {
        Http::fake([
            'slack.com/api/users.info' => Http::response([
                'ok' => true,
                'user' => ['profile' => ['email' => $this->user->email]],
            ]),
            'slack.com/api/conversations.history' => Http::response([
                'ok' => true,
                // Newest first, as Slack sends it.
                'messages' => [
                    ['type' => 'message', 'user' => 'U0001', 'text' => 'any update?', 'ts' => self::LATER_TS],
                    ['type' => 'message', 'subtype' => 'channel_join', 'user' => 'U0002', 'text' => '<@U0002> has joined', 'ts' => '1700000250.000100'],
                    ['type' => 'message', 'bot_id' => 'B0001', 'text' => 'I am a bot', 'ts' => '1700000150.000100'],
                    ['type' => 'message', 'user' => 'U0001', 'text' => 'when does the sync land?', 'ts' => self::ROOT_TS, 'reply_count' => 1],
                ],
                'response_metadata' => ['next_cursor' => $nextCursor],
            ]),
            'slack.com/api/conversations.replies' => Http::response([
                'ok' => true,
                'messages' => [
                    ['type' => 'message', 'user' => 'U0001', 'text' => 'when does the sync land?', 'ts' => self::ROOT_TS, 'reply_count' => 1],
                    ['type' => 'message', 'user' => 'U0003', 'text' => 'Thursday', 'ts' => self::REPLY_TS, 'thread_ts' => self::ROOT_TS],
                ],
                'response_metadata' => ['next_cursor' => ''],
            ]),
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);
    }

    private function messagesOnChannel(): Collection
    {
        $channel = Channel::where(
            'slug',
            'slack-' . strtolower($this->teamId . '-' . $this->slackChannelId)
        )->first();

        if ($channel === null) {
            return new Collection();
        }

        return Message::whereHas('channels', fn ($query) => $query->where('channels.id', $channel->getId()))
            ->orderBy('id')
            ->get();
    }
}
