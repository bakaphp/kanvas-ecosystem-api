<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Hermes\Providers\HermesProvider;
use Kanvas\Connectors\OpenClaw\Providers\OpenClawProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Actions\RuntimeAgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Tests\TestCase;

class RuntimeAgentChannelResponderActionTest extends TestCase
{
    public function testRelaysInboundMessageToRuntimeAndPersistsReply(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('hello agent', fromMe: false);

        $provider = new FakeChannelRuntimeProvider('the agent reply');
        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = $provider;

        $reply = $action->execute();

        $this->assertSame('hello agent', $provider->lastMessage);
        $this->assertSame('kanvas-channel-' . $channel->getId(), $provider->lastSessionKey);

        $payload = $reply->getMessage();
        $this->assertSame('the agent reply', $payload['content']);
        $this->assertTrue($payload['from_ia']);
        $this->assertTrue($payload['from_me']);

        $this->assertTrue(
            $channel->messages()->wherePivot('messages_id', $reply->getId())->exists(),
            'Agent reply should be attached to the channel',
        );
    }

    public function testSkipsMessagesComingFromTheAgentSide(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('agent talking', fromMe: true);

        $provider = new FakeChannelRuntimeProvider('should not be used');
        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = $provider;

        $reply = $action->execute();

        $this->assertSame($message->getId(), $reply->getId());
        $this->assertFalse($provider->wasCalled, 'Runtime should not be hit for outbound messages');
    }

    public function testThrowsWhenInboundMessageHasNoContent(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('', fromMe: false);

        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = new FakeChannelRuntimeProvider('unused');

        $this->expectException(ValidationException::class);
        $action->execute();
    }

    public function testRoutesToOpenClawForAnAgentWithNoDeclaredProvider(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('hi', fromMe: false);

        $provider = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel)
            ->resolveProvider();

        $this->assertInstanceOf(OpenClawProvider::class, $provider);
    }

    public function testRoutesToHermesForAHermesTypedAgent(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('hi', fromMe: false);

        $agent->type->provider = AgentProviderEnum::HERMES->value;
        $agent->type->saveOrFail();
        $agent->unsetRelation('type');

        $provider = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel)
            ->resolveProvider();

        $this->assertInstanceOf(HermesProvider::class, $provider);
    }

    /**
     * @return array{0: Agent, 1: Channel, 2: Message}
     */
    private function makeChannelConversation(string $content, bool $fromMe): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
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

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'message' => [
                    'content' => $content,
                    'chat_jid' => 'test-jid@channel',
                    'from_me' => $fromMe,
                ],
            ]);

        return [$agent, $channel, $message];
    }
}

/**
 * Canned-reply runtime provider — lets the action run end to end without SSH or a container.
 */
class FakeChannelRuntimeProvider extends AbstractAgentRuntimeProvider
{
    public bool $wasCalled = false;
    public ?string $lastMessage = null;
    public ?string $lastSessionKey = null;

    public function __construct(private readonly string $reply)
    {
    }

    #[Override]
    public function name(): AgentProviderEnum
    {
        return AgentProviderEnum::OPENCLAW;
    }

    #[Override]
    public function chat(
        Agent $agent,
        string $message,
        ?string $sessionKey = null,
        array $images = [],
    ): string {
        $this->wasCalled = true;
        $this->lastMessage = $message;
        $this->lastSessionKey = $sessionKey;

        return $this->reply;
    }
}

/**
 * Exposes resolveProvider() and allows a fake runtime to be injected so the relay path
 * is testable without a live deployment.
 */
class TestableRuntimeAgentChannelResponderAction extends RuntimeAgentChannelResponderAction
{
    public ?AgentRuntimeProvider $fakeProvider = null;

    #[Override]
    public function resolveProvider(): AgentRuntimeProvider
    {
        return $this->fakeProvider ?? parent::resolveProvider();
    }
}
