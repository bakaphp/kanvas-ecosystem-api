<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Actions\ConnectSlackListenerAction;
use Kanvas\Connectors\Slack\Actions\DisconnectSlackListenerAction;
use Kanvas\Connectors\Slack\Actions\GenerateSlackListenerManifestAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Jobs\JoinAllPublicChannelsJob;
use Kanvas\Connectors\Slack\Services\SlackListenerReceiverService;
use Kanvas\Connectors\Slack\Services\SlackListenerStatusService;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackListenerWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class SlackListenerSetupTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackListenerWebhookJob::class],
            ['name' => 'ProcessSlackListenerWebhookJob']
        );
    }

    public function testTheManifestCannotPostAnything(): void
    {
        $manifest = json_decode($this->manifest()['manifest_json'], true);
        $scopes = $manifest['oauth_config']['scopes']['bot'];

        // A token minted from this manifest is incapable of writing.
        $this->assertNotContains('chat:write', $scopes);
        $this->assertNotContains('im:history', $scopes);
        $this->assertTrue($manifest['features']['app_home']['messages_tab_read_only_enabled']);
    }

    public function testTheManifestSubscribesToEveryConversationSurfaceItCanReach(): void
    {
        $manifest = json_decode($this->manifest()['manifest_json'], true);
        $events = $manifest['settings']['event_subscriptions']['bot_events'];

        $this->assertContains('message.channels', $events);
        $this->assertContains('message.groups', $events);
        $this->assertContains('message.mpim', $events);
        // Without this the initial sweep is a snapshot and coverage decays from day two.
        $this->assertContains('channel_created', $events);
        // The bot has to be a member to hear anything, so it must be able to join itself.
        $this->assertContains('channels:join', $manifest['oauth_config']['scopes']['bot']);
    }

    public function testTheReceiverIsCreatedOnceAndKeepsItsUrl(): void
    {
        $first = $this->manifest();
        $second = $this->manifest();

        // The customer's Slack app points at this URL forever — regenerating must not move it.
        $this->assertSame($first['request_url'], $second['request_url']);
        $this->assertStringContainsString('/v1/receiver/', $first['request_url']);
        // The install link carries the whole manifest url-encoded in the query string.
        $this->assertStringContainsString(urlencode($first['request_url']), $first['install_url']);
    }

    /**
     * There is no agent in the listener, so setup must never depend on the ai-agent-user-id config —
     * nothing in the codebase sets that key and an admin has no way to reach it.
     */
    public function testSetupWorksForACompanyWithNoAiAgentUserConfigured(): void
    {
        $this->company->del(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value);

        $manifest = $this->manifest();

        $this->assertStringContainsString('/v1/receiver/', $manifest['request_url']);

        $receiver = new SlackListenerReceiverService()->findForCompany($this->kanvasApp, $this->company);
        $this->assertSame($this->user->getId(), $receiver->users_id);
    }

    public function testAUserTokenIsRejectedUpFront(): void
    {
        $this->expectException(ValidationException::class);

        new ConnectSlackListenerAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: $this->user,
            botToken: 'xoxp-a-user-token',
            signingSecret: 'shhh',
        )->execute();
    }

    public function testConnectingStoresTheCredentialsAndStartsTheSweep(): void
    {
        Queue::fake();
        Http::fake([
            'slack.com/api/auth.test' => Http::response([
                'ok' => true,
                'user_id' => 'UBOT001',
                'team_id' => 'T001',
                'team' => 'Acme',
            ]),
        ]);

        $result = new ConnectSlackListenerAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: $this->user,
            botToken: 'xoxb-real-token',
            signingSecret: 'shhh',
            channelDenyList: ['C666'],
        )->execute();

        $this->assertTrue($result['connected']);
        $this->assertSame('Acme', $result['team_name']);

        $receiver = new SlackListenerReceiverService()->findForCompany($this->kanvasApp, $this->company);
        $this->assertSame('UBOT001', $receiver->configuration[ConfigurationEnum::BOT_USER_ID->value]);
        $this->assertSame(['C666'], $receiver->configuration[ConfigurationEnum::CHANNEL_DENY_LIST->value]);

        Queue::assertPushed(JoinAllPublicChannelsJob::class);
    }

    public function testDisconnectingDropsTheTokenButKeepsTheReceiver(): void
    {
        Queue::fake();
        Http::fake([
            'slack.com/api/auth.test' => Http::response([
                'ok' => true,
                'user_id' => 'UBOT002',
                'team_id' => 'T002',
                'team' => 'Acme',
            ]),
        ]);

        new ConnectSlackListenerAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: $this->user,
            botToken: 'xoxb-real-token',
            signingSecret: 'shhh',
        )->execute();

        $urlBefore = new SlackListenerStatusService()
            ->forCompany($this->kanvasApp, $this->company)['request_url'];

        $this->assertTrue(
            new DisconnectSlackListenerAction($this->kanvasApp, $this->company)->execute()
        );

        $this->assertNull(new SlackListenerStatusService()->forCompany($this->kanvasApp, $this->company));

        $receiver = new SlackListenerReceiverService()->findForCompany($this->kanvasApp, $this->company);
        $this->assertArrayNotHasKey(ConfigurationEnum::BOT_TOKEN->value, $receiver->configuration);
        // Reconnecting has to land on the same row, so the row survives.
        $this->assertSame($urlBefore, $receiver->getUrl());
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return new GenerateSlackListenerManifestAction($this->kanvasApp, $this->company, $this->user)->execute();
    }
}
