<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Actions\ConnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\DisconnectSlackAgentAction;
use Kanvas\Connectors\Slack\Actions\GenerateSlackManifestAction;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\CustomFieldEnum;
use Kanvas\Connectors\Slack\Services\SlackConnectionStatusService;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class ConnectSlackAgentActionTest extends TestCase
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
            ['model_name' => ProcessSlackWebhookJob::class],
            ['name' => 'ProcessSlackWebhookJob']
        );
    }

    public function testManifestCarriesTheAgentsOwnReceiverUrl(): void
    {
        $agent = $this->agent('Sofia');

        $result = $this->manifestFor($agent);
        $manifest = json_decode($result['manifest_json'], true);

        $this->assertSame(
            $result['request_url'],
            $manifest['settings']['event_subscriptions']['request_url']
        );
        $this->assertSame('Sofia', $manifest['display_information']['name']);
        $this->assertFalse($manifest['settings']['socket_mode_enabled']);
        // The DM surface — without it, clicking the agent gives the user nowhere to type.
        $this->assertTrue($manifest['features']['app_home']['messages_tab_enabled']);
        // Without users:read.email, users.info returns no email — and email is the only thing
        // tying a Slack member to a Kanvas user.
        $this->assertContains('users:read.email', $manifest['oauth_config']['scopes']['bot']);
        $this->assertStringContainsString('api.slack.com/apps?new_app=1', $result['install_url']);
    }

    public function testAnUnnamedAgentStillGetsAValidManifest(): void
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => null, 'user_id' => $this->user->getId()]);

        $manifest = json_decode($this->manifestFor($agent)['manifest_json'], true);

        // Slack rejects an empty app name / bot display_name — the fallback keeps provisioning alive.
        $this->assertSame('Kanvas Agent ' . $agent->getId(), $manifest['display_information']['name']);
        $this->assertSame('Kanvas Agent ' . $agent->getId(), $manifest['features']['bot_user']['display_name']);
    }

    public function testEachAgentGetsItsOwnReceiver(): void
    {
        $first = $this->manifestFor($this->agent('Sofia'));
        $second = $this->manifestFor($this->agent('Marcus'));

        // The bug this guards: every other connector keys its receiver on
        // (apps_id, companies_id, action_id), which would give agent #2 agent #1's Slack app.
        $this->assertNotSame($first['request_url'], $second['request_url']);
    }

    public function testRegeneratingTheManifestReusesTheSameReceiver(): void
    {
        $agent = $this->agent('Sofia');

        $this->assertSame(
            $this->manifestFor($agent)['request_url'],
            $this->manifestFor($agent->refresh())['request_url'],
            'A new receiver would orphan the URL already baked into the customer\'s Slack app.'
        );
    }

    public function testConnectStoresTheTokenAndReadsBackTheBotIdentity(): void
    {
        $this->fakeAuthTest();
        $agent = $this->agent('Sofia');

        $result = new ConnectSlackAgentAction(
            agent: $agent,
            botToken: 'xoxb-real-token',
            signingSecret: 'shhh',
        )->execute();

        $this->assertTrue($result['connected']);
        $this->assertSame('UBOT123', $result['bot_user_id']);
        $this->assertSame('Acme', $result['team_name']);

        // Shared key, not a Slack-private one: a container runtime reads the same credential.
        $this->assertSame('xoxb-real-token', $agent->refresh()->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value));

        $configuration = $this->receiverOf($agent)->configuration;
        $this->assertSame('shhh', $configuration[ConfigurationEnum::SIGNING_SECRET->value]);
        $this->assertSame('UBOT123', $configuration[ConfigurationEnum::BOT_USER_ID->value]);
        $this->assertSame('T0001', $configuration[ConfigurationEnum::TEAM_ID->value]);
        $this->assertSame($agent->getId(), $configuration[ConfigurationEnum::AGENT_ID->value]);
    }

    public function testAUserTokenIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new ConnectSlackAgentAction(
            agent: $this->agent('Sofia'),
            botToken: 'xoxp-a-user-token',
            signingSecret: 'shhh',
        )->execute();
    }

    public function testDisconnectDeactivatesTheReceiverButKeepsItsUrl(): void
    {
        $this->fakeAuthTest();
        $agent = $this->agent('Sofia');

        $connected = new ConnectSlackAgentAction(
            agent: $agent,
            botToken: 'xoxb-real-token',
            signingSecret: 'shhh',
        )->execute();

        new DisconnectSlackAgentAction($agent)->execute();

        $receiver = $this->receiverOf($agent->refresh());

        $this->assertFalse($receiver->is_active);
        $this->assertNull($agent->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value));
        // The customer's Slack app still posts here and we can't edit an app we don't own.
        $this->assertSame($connected['request_url'], $receiver->getUrl());
    }

    public function testConnectionStatusReflectsTheLifecycle(): void
    {
        $this->fakeAuthTest();
        $agent = $this->agent('Sofia');
        $status = new SlackConnectionStatusService();

        // Never provisioned → not connected.
        $this->assertNull($status->forAgent($agent));

        new ConnectSlackAgentAction(
            agent: $agent,
            botToken: 'xoxb-real-token',
            signingSecret: 'shhh',
        )->execute();

        $connected = $status->forAgent($agent->refresh());
        $this->assertTrue($connected['connected']);
        $this->assertSame('Acme', $connected['team_name']);
        $this->assertSame('UBOT123', $connected['bot_user_id']);

        // Disconnected → not connected, even though the receiver row survives.
        new DisconnectSlackAgentAction($agent)->execute();
        $this->assertNull($status->forAgent($agent->refresh()));
    }

    public function testTheGraphQLSurfaceIsWired(): void
    {
        $this->fakeAuthTest();
        $agent = $this->agent('Sofia');

        // Pin the request to the app the agent lives under. Without the app-key header the resolver's
        // app(Apps::class) is resolved from the request host, which needn't match $this->kanvasApp —
        // then getByIdFromCompanyApp can't find the agent and the query returns null.
        $headers = [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $this->kanvasApp->keys()->first()->client_secret_id,
        ];

        $manifest = $this->graphQL('
            query ($id: ID!) {
                slackAgentManifest(agent_id: $id) { manifest_json install_url request_url }
            }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->json('data.slackAgentManifest');

        $this->assertStringContainsString('/v1/receiver/', $manifest['request_url']);
        $this->assertStringContainsString('api.slack.com/apps?new_app=1', $manifest['install_url']);

        $this->graphQL('
            mutation ($input: ConnectSlackAgentInput!) {
                connectSlackAgent(input: $input) { connected team_name bot_user_id }
            }
        ', ['input' => [
            'agent_id' => $agent->getId(),
            'bot_token' => 'xoxb-real-token',
            'signing_secret' => 'shhh',
        ]], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.connectSlackAgent.connected', true)
            ->assertJsonPath('data.connectSlackAgent.team_name', 'Acme');

        $this->graphQL('
            mutation ($id: ID!) { disconnectSlackAgent(agent_id: $id) }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.disconnectSlackAgent', true);

        // After disconnect the status query goes null — the tile flips back to "not connected".
        $this->graphQL('
            query ($id: ID!) { slackAgentConnection(agent_id: $id) { connected team_name } }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.slackAgentConnection', null);
    }

    private function fakeAuthTest(): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response([
                'ok' => true,
                'user_id' => 'UBOT123',
                'team_id' => 'T0001',
                'team' => 'Acme',
            ]),
        ]);
    }

    private function agent(string $name): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => $name, 'user_id' => $this->user->getId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFor(Agent $agent): array
    {
        return new GenerateSlackManifestAction($agent)->execute();
    }

    private function receiverOf(Agent $agent): ReceiverWebhook
    {
        return ReceiverWebhook::getById(
            (int) $agent->get(CustomFieldEnum::RECEIVER_ID->value),
            $this->kanvasApp
        );
    }
}
