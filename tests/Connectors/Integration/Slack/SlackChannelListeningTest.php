<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Actions\JoinAllSlackChannelsAction;
use Kanvas\Connectors\Slack\Actions\SetSlackChannelListeningAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackReceiverService;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class SlackChannelListeningTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();

        $agentType = AgentType::factory()->withAppId($app->getId())->create();

        $this->agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'user_id' => $user->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);

        $this->agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test-token');

        WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackWebhookJob::class],
            ['name' => 'ProcessSlackWebhookJob']
        );
    }

    public function testEnablingListeningJoinsEveryPublicChannelItIsNotAlreadyIn(): void
    {
        $this->fakeConversations([
            ['id' => 'C001', 'name' => 'general', 'is_member' => false],
            ['id' => 'C002', 'name' => 'random', 'is_member' => true],
            ['id' => 'C003', 'name' => 'sales', 'is_member' => false],
        ]);

        $result = new SetSlackChannelListeningAction($this->agent, enabled: true)->execute();

        $this->assertTrue($result['listening_all_channels']);
        $this->assertSame(['C001', 'C003'], $result['joined_channels']);
        $this->assertSame(['C002'], $result['already_member_channels']);
        $this->assertSame([], $result['failed_channels']);

        // Re-joining a channel the bot is already in would post a redundant "has joined" in it.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'conversations.join')
            && ($request['channel'] ?? null) === 'C002');

        $this->assertTrue(
            (bool) new SlackReceiverService()->forAgent($this->agent)
                ->configuration[ConfigurationEnum::LISTEN_ALL_CHANNELS->value]
        );
    }

    public function testAChannelTheBotCannotEnterDoesNotAbortTheSweep(): void
    {
        Http::fake([
            'slack.com/api/conversations.list' => Http::response([
                'ok' => true,
                'channels' => [
                    ['id' => 'CLOCKED', 'name' => 'execs', 'is_member' => false],
                    ['id' => 'COPEN', 'name' => 'general', 'is_member' => false],
                ],
            ]),
            'slack.com/api/conversations.join' => Http::sequence()
                ->push(['ok' => false, 'error' => 'restricted_action'])
                ->push(['ok' => true]),
        ]);

        $result = new JoinAllSlackChannelsAction($this->agent)->execute();

        $this->assertSame(['CLOCKED'], $result['failed']);
        $this->assertSame(['COPEN'], $result['joined']);
    }

    public function testEveryPageOfChannelsIsWalked(): void
    {
        Http::fake([
            'slack.com/api/conversations.list' => Http::sequence()
                ->push([
                    'ok' => true,
                    'channels' => [['id' => 'C001', 'name' => 'a', 'is_member' => false]],
                    'response_metadata' => ['next_cursor' => 'page2'],
                ])
                ->push([
                    'ok' => true,
                    'channels' => [['id' => 'C002', 'name' => 'b', 'is_member' => false]],
                    'response_metadata' => ['next_cursor' => ''],
                ]),
            'slack.com/api/conversations.join' => Http::response(['ok' => true]),
        ]);

        $result = new JoinAllSlackChannelsAction($this->agent)->execute();

        $this->assertSame(['C001', 'C002'], $result['joined']);
    }

    public function testDisablingListeningLeavesMembershipUntouched(): void
    {
        $this->fakeConversations([['id' => 'C001', 'name' => 'general', 'is_member' => false]]);

        $result = new SetSlackChannelListeningAction($this->agent, enabled: false)->execute();

        $this->assertFalse($result['listening_all_channels']);
        $this->assertSame([], $result['joined_channels']);

        // Turning listening off is a Kanvas-side switch. Making the bot leave every channel would be
        // destructive and unrecoverable without re-inviting it by hand.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'conversations.'));
    }

    public function testListeningCanBeEnabledWithoutAnnouncingTheBotWorkspaceWide(): void
    {
        $this->fakeConversations([['id' => 'C001', 'name' => 'general', 'is_member' => false]]);

        $result = new SetSlackChannelListeningAction(
            $this->agent,
            enabled: true,
            joinExistingChannels: false,
        )->execute();

        $this->assertTrue($result['listening_all_channels']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'conversations.join'));
    }

    private function fakeConversations(array $channels): void
    {
        Http::fake([
            'slack.com/api/conversations.list' => Http::response([
                'ok' => true,
                'channels' => $channels,
            ]),
            'slack.com/api/conversations.join' => Http::response(['ok' => true]),
        ]);
    }
}
