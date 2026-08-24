<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Jobs\JoinAllPublicChannelsJob;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackListenerWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class JoinAllPublicChannelsJobTest extends TestCase
{
    private Apps $kanvasApp;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $user = auth()->user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackListenerWebhookJob::class],
            ['name' => 'ProcessSlackListenerWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($user->getId())
            ->company($user->getCurrentCompany()->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    ConfigurationEnum::BOT_TOKEN->value => 'xoxb-test-token',
                ],
            ]);
    }

    public function testItJoinsOnlyTheChannelsItIsNotAlreadyIn(): void
    {
        Queue::fake();
        $this->fakeConversationsList([
            ['id' => 'C1', 'is_member' => false],
            ['id' => 'C2', 'is_member' => true],
            ['id' => 'C3', 'is_member' => false],
        ]);

        new JoinAllPublicChannelsJob($this->kanvasApp, $this->receiver)->handle();

        $this->assertJoinWasSentFor('C1');
        $this->assertJoinWasSentFor('C3');
        Http::assertNotSent(
            fn ($request) => str_contains($request->url(), 'conversations.join')
                && ($request['channel'] ?? null) === 'C2'
        );

        $this->assertSame(
            2,
            (int) $this->receiver->refresh()->configuration[ConfigurationEnum::CHANNELS_JOINED->value]
        );
    }

    public function testItReschedulesItselfWithTheCursorInsteadOfLoopingThePage(): void
    {
        Queue::fake();
        $this->fakeConversationsList([['id' => 'C1', 'is_member' => false]], nextCursor: 'page-2');

        new JoinAllPublicChannelsJob($this->kanvasApp, $this->receiver)->handle();

        // Sweeping in one pass gets the app rate-limited off Slack; a page a minute finishes.
        Queue::assertPushed(
            JoinAllPublicChannelsJob::class,
            fn (JoinAllPublicChannelsJob $job): bool => $job->cursor === 'page-2'
                && $job->joinedSoFar === 1
                // Same queue as the firehose, so the sweep needs no container of its own.
                && $job->queue === 'slack-ingest'
        );
    }

    public function testTheLastPageDoesNotRescheduleAnother(): void
    {
        Queue::fake();
        $this->fakeConversationsList([['id' => 'C1', 'is_member' => false]]);

        new JoinAllPublicChannelsJob($this->kanvasApp, $this->receiver)->handle();

        Queue::assertNotPushed(JoinAllPublicChannelsJob::class);
    }

    public function testAnUnjoinableChannelDoesNotAbandonTheRestOfThePage(): void
    {
        Queue::fake();

        Http::fake([
            'slack.com/api/conversations.list' => Http::response([
                'ok' => true,
                'channels' => [
                    ['id' => 'C1', 'is_member' => false],
                    ['id' => 'C2', 'is_member' => false],
                ],
            ]),
            'slack.com/api/conversations.join' => Http::sequence()
                ->push(['ok' => false, 'error' => 'is_archived'])
                ->push(['ok' => true]),
        ]);

        new JoinAllPublicChannelsJob($this->kanvasApp, $this->receiver)->handle();

        $this->assertJoinWasSentFor('C2');
        $this->assertSame(
            1,
            (int) $this->receiver->refresh()->configuration[ConfigurationEnum::CHANNELS_JOINED->value]
        );
    }

    private function fakeConversationsList(array $channels, string $nextCursor = ''): void
    {
        Http::fake([
            'slack.com/api/conversations.list' => Http::response([
                'ok' => true,
                'channels' => $channels,
                'response_metadata' => ['next_cursor' => $nextCursor],
            ]),
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);
    }

    private function assertJoinWasSentFor(string $channelId): void
    {
        Http::assertSent(
            fn ($request) => str_contains($request->url(), 'conversations.join')
                && ($request['channel'] ?? null) === $channelId
        );
    }
}
