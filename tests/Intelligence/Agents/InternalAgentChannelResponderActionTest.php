<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\InternalAgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Ramsey\Uuid\Uuid;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * End-to-end for the internal (non-connector) channel responder: an inbound Message on
 * a channel → AgentChatKernel → Neuron agent reply persisted back on the same channel.
 * Proves the kernel-based path works for Neuron agents, unlike the runtime-only
 * RuntimeAgentChannelResponderAction. No connector outbound, so execute() completes
 * cleanly and returns the reply.
 */
class InternalAgentChannelResponderActionTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // The kernel's Neuron path writes conversation turns as public Messages, which Scout
        // would push to Typesense (failing in the test env). Disable message indexing for this app.
        app(Apps::class)->set('message_disable_searchable', true);
    }

    public function tearDown(): void
    {
        app(Apps::class)->set('message_disable_searchable', false);

        parent::tearDown();
    }

    public function testInboundMessageTriggersNeuronAgentReplyPersistedOnChannel(): void
    {
        [$agent, $channel, $inbound] = $this->makeChannelConversation('Hello, I am interested in a car', fromMe: false);

        $reply = new InternalAgentChannelResponderAction($agent, $inbound, $channel)->execute();

        $payload = $reply->getMessage();
        $this->assertStringContainsString('Hola Mundo', (string) ($payload['content'] ?? ''));
        $this->assertTrue($payload['from_ia']);
        $this->assertTrue($payload['from_me']);

        $this->assertTrue(
            $channel->messages()->wherePivot('messages_id', $reply->getId())->exists(),
            'Agent reply should be attached to the channel',
        );
    }

    public function testKeepsOneConversationPerChannelAcrossMessages(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        [$agent, $channel, $first] = $this->makeChannelConversation('first message', fromMe: false);
        new InternalAgentChannelResponderAction($agent, $first, $channel)->execute();

        // A second inbound on the SAME channel must continue the same conversation, not start a new one.
        $second = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'message' => [
                    'content' => 'second message',
                    'chat_jid' => 'test-jid@channel',
                    'from_me' => false,
                ],
            ]);
        $channel->addMessage($second);
        new InternalAgentChannelResponderAction($agent, $second, $channel)->execute();

        // The channel is find-or-created into exactly one durable Session.
        $this->assertSame(
            1,
            Session::where('channel_id', $channel->getId())->where('agents_id', $agent->getId())->count(),
            'A channel must resolve to one durable session, not one per message',
        );

        // Both turns' Neuron conversation rows share the single channel-derived conversation id.
        // KanvasMessageHistory folds an over-length session-uuid slug into a deterministic UUID
        // (conversation_id is varchar(36)), so mirror that here.
        $sessionUuid = SessionChannelService::buildChannelSessionUuid($channel, $app, $company);
        $conversationId = strlen($sessionUuid) <= 36
            ? $sessionUuid
            : Uuid::uuid5(Uuid::NAMESPACE_OID, $sessionUuid)->toString();

        $distinct = DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->distinct()
            ->count('conversation_id');
        $this->assertSame(1, $distinct, 'Every turn on the channel must belong to one conversation');
    }

    public function testSkipsMessagesComingFromTheAgentSide(): void
    {
        [$agent, $channel, $inbound] = $this->makeChannelConversation('agent talking', fromMe: true);

        $reply = new InternalAgentChannelResponderAction($agent, $inbound, $channel)->execute();

        $this->assertSame($inbound->getId(), $reply->getId());
        $this->assertFalse(
            $channel->messages()
                ->wherePivot('messages_id', '!=', $inbound->getId())
                ->exists(),
            'No agent reply should be created for an outbound message',
        );
    }

    public function testNotifiesInboundAuthorWhenAgentReplies(): void
    {
        Notification::fake();
        $user = auth()->user();

        [$agent, $channel, $inbound] = $this->makeChannelConversation('hello agent', fromMe: false);
        $inbound->users_id = $user->getId();
        $inbound->saveOrFail();
        $inbound->unsetRelation('user');

        new InternalAgentChannelResponderAction($agent, $inbound, $channel)->execute();

        $recipient = Users::getById($user->getId());

        Notification::assertSentTo(
            $recipient,
            AgentReplyNotification::class,
        );
    }

    /**
     * @return array{0: Agent, 1: Channel, 2: Message}
     */
    private function makeChannelConversation(string $content, bool $fromMe): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // getAiAgentUserOrFail() resolves the user the reply is posted as.
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sales',
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
                'soul' => 'Test sales agent',
                'instructions' => 'Always respond with: Hola Mundo',
                'output_format' => 'plain text',
            ]);

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $user,
                'entity_id' => $company->getId(),
                'entity_namespace' => Companies::class,
                'name' => 'Agent channel · ' . uniqid(),
                'slug' => 'agent_channel_' . uniqid(),
            ]),
        )->execute();

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'message' => [
                    'content' => $content,
                    'chat_jid' => 'test-jid@channel',
                    'from_me' => $fromMe,
                ],
            ]);

        $channel->addMessage($inbound);

        return [$agent, $channel, $inbound];
    }
}
