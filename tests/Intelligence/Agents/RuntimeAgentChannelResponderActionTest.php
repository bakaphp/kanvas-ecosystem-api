<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Hermes\Providers\HermesProvider;
use Kanvas\Connectors\OpenClaw\Providers\OpenClawProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Actions\RuntimeAgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Notifications\AgentReplyNotification;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
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
        $this->assertSame(
            $channel->uuid,
            $provider->lastSessionKey,
            'Runtime conversation must key on the channel uuid',
        );

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

    public function testForwardsImageAttachmentsWhoseFileTypeIsAMimeType(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('look at this', fromMe: false);

        // MIME `file_type` + an extensionless URL — exactly the shape that used to be
        // silently dropped by the old extension-only check.
        $imageUrl = 'https://cdn.example.com/whatsapp/media/' . uniqid();
        $this->attachFile($message, $imageUrl, name: 'whatsapp-media', fileType: 'image/jpeg');

        $provider = new FakeChannelRuntimeProvider('seen it');
        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = $provider;

        $action->execute();

        $this->assertContains($imageUrl, $provider->lastImages);
    }

    public function testForwardsNonImageAttachmentsAsLinksInTheMessageText(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('check this doc', fromMe: false);

        $pdfUrl = 'https://cdn.example.com/docs/quote-' . uniqid() . '.pdf';
        $this->attachFile($message, $pdfUrl, name: 'quote.pdf', fileType: 'application/pdf');

        $provider = new FakeChannelRuntimeProvider('got it');
        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = $provider;

        $action->execute();

        // The runtime rejects non-image content, so a PDF must reach the agent as a link
        // in the message text — never in the multimodal images slot.
        $this->assertStringContainsString($pdfUrl, (string) $provider->lastMessage);
        $this->assertNotContains($pdfUrl, $provider->lastImages);
    }

    public function testSendsImageOnlyMessagesThatHaveNoTextCaption(): void
    {
        [$agent, $channel, $message] = $this->makeChannelConversation('', fromMe: false);

        $imageUrl = 'https://cdn.example.com/whatsapp/media/' . uniqid() . '.jpg';
        $this->attachFile($message, $imageUrl, name: 'photo.jpg', fileType: 'jpg');

        $provider = new FakeChannelRuntimeProvider('nice photo');
        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = $provider;

        $action->execute();

        $this->assertContains($imageUrl, $provider->lastImages);
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

    public function testNotifiesInboundAuthorWhenAgentReplies(): void
    {
        Notification::fake();
        $user = auth()->user();

        [$agent, $channel, $message] = $this->makeChannelConversation('hello agent', fromMe: false);
        $message->users_id = $user->getId();
        $message->saveOrFail();
        $message->unsetRelation('user');

        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = new FakeChannelRuntimeProvider('the agent reply');
        $action->execute();

        // The action resolves the recipient via Users::getById(), so assert against the
        // same canonical Users instance — assertSentTo keys notifications by concrete class.
        $recipient = Users::getById($user->getId());

        Notification::assertSentTo(
            $recipient,
            AgentReplyNotification::class,
            function (AgentReplyNotification $notification) use ($recipient): bool {
                return in_array('push', $notification->channels, true)
                    && in_array('expo', $notification->channels, true)
                    && $notification->toOneSignal($recipient)['message'] === 'the agent reply';
            }
        );
    }

    public function testDoesNotNotifyWhenSkippingAgentSideMessage(): void
    {
        Notification::fake();

        [$agent, $channel, $message] = $this->makeChannelConversation('agent talking', fromMe: true);
        $message->users_id = auth()->user()->getId();
        $message->saveOrFail();

        $action = new TestableRuntimeAgentChannelResponderAction($agent, $message, $channel);
        $action->fakeProvider = new FakeChannelRuntimeProvider('should not be used');
        $action->execute();

        Notification::assertNothingSent();
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

    private function attachFile(Message $message, string $url, string $name, string $fileType): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $file = new Filesystem();
        $file->apps_id = $app->getId();
        $file->companies_id = $company->getId();
        $file->users_id = $user->getId();
        $file->name = $name;
        $file->path = '/tmp/' . $name;
        $file->url = $url;
        $file->size = '0';
        $file->file_type = $fileType;
        $file->is_deleted = 0;
        $file->saveOrFail();

        $message->addFile($file, 'attachment');
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
    /** @var list<string> */
    public array $lastImages = [];

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
        array $additionalTools = [],
    ): string {
        $this->wasCalled = true;
        $this->lastMessage = $message;
        $this->lastSessionKey = $sessionKey;
        $this->lastImages = $images;

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
