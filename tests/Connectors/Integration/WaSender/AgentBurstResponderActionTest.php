<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentBurstResponderAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\MultiRecordNeuronAgentStub;
use Tests\Stubs\Intelligence\StructuredNeuronAgentStub;
use Tests\TestCase;

/**
 * The newsroom case: a group the agent listens to but does not speak in.
 *
 * WhatsApp restricts accounts that look automated, so a publishing agent is configured to stay
 * silent unless addressed. Its work still has to survive — the reply message is what carries
 * `response_json` and fires the message-created rule that WordPress publishing hangs off, so
 * gating message creation on the mention would silently throw every article away.
 */
final class AgentBurstResponderActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow', 'intelligence'];

    private const string GROUP_JID = '18097070426-1436467587@g.us';

    public function testASilentBurstStillFilesThePublishableArticle(): void
    {
        $result = $this->runBurst(shouldReply: false);

        $this->assertFalse($result['replied'], 'A group on mention mode must not post back');

        $agentMessage = $this->latestAgentMessage();

        $this->assertNotNull($agentMessage, 'The agent turn must be filed even when it stays silent');
        $this->assertTrue((bool) $agentMessage->message['from_ia']);
        $this->assertArrayHasKey(
            'response_json',
            $agentMessage->message,
            'Without response_json the WordPress activity has no article to publish'
        );
        $this->assertSame(
            'Education accelerates classroom construction in El Seibo',
            $agentMessage->message['response_json']['title']
        );
        $this->assertTrue(
            $agentMessage->tags()->where('name', 'not-delivered')->exists(),
            'A withheld reply must be distinguishable from a delivered one'
        );
    }

    public function testAnAddressedBurstSendsTheReplyIntoTheGroup(): void
    {
        $result = $this->runBurst(shouldReply: true);

        $this->assertTrue($result['replied']);
        $this->assertCount(1, self::$sent, 'The agent must post back when it was addressed');
        $this->assertSame(self::GROUP_JID, self::$sent[0]['to']);
        $this->assertSame($result['response'], self::$sent[0]['text']);

        $agentMessage = $this->latestAgentMessage();

        $this->assertNotNull($agentMessage);
        $this->assertFalse($agentMessage->tags()->where('name', 'not-delivered')->exists());
    }

    /**
     * The silent path must reach WhatsApp exactly zero times — that is the whole point of mention
     * mode under an account restriction.
     */
    public function testASilentBurstSendsNothingToWhatsApp(): void
    {
        $this->runBurst(shouldReply: false);

        $this->assertSame([], self::$sent);
    }

    /**
     * The photos hang off the burst's child messages, but everything downstream reads the agent's
     * reply — `PushMessageToWordPressActivity` skips inbound messages outright and takes its
     * featured image from `$message->attachmentUrls()`. Without carrying the whole burst forward,
     * the article publishes with no image even though the agent saw the photos.
     */
    public function testTheReplyCarriesEveryPhotoInTheBurstNotJustTheHeads(): void
    {
        $this->runBurst(shouldReply: false, withPhotoChild: true);

        $reply = $this->latestAgentMessage();

        $this->assertNotNull($reply);
        $this->assertCount(
            1,
            $reply->attachmentUrls()['images'],
            "The agent's reply must carry the burst's photo so the publisher can use it"
        );
    }

    /**
     * Two press releases in one burst come back as a fenced JSON LIST, which decoded to nothing: the
     * reply was filed with the raw JSON as its body and no `response_json`, and the publisher shipped
     * that dump as the article. Bare-list was worse — empty reply text, whole turn discarded.
     */
    public function testAMultiArticleReplyIsFiledWithItsEnvelopeNotAsAJsonDump(): void
    {
        $this->runBurst(shouldReply: false, handler: MultiRecordNeuronAgentStub::class);

        $reply = $this->latestAgentMessage();

        $this->assertNotNull($reply, 'A list answer must still file the agent turn');

        $envelope = $reply->message['response_json'] ?? null;

        $this->assertIsArray($envelope, 'The whole list must survive on the message');
        $this->assertCount(2, $envelope);
        $this->assertSame('Foundation delivers school supplies in Herrera', $envelope[0]['title']);
        $this->assertSame('Congressman presents his legislative report', $envelope[1]['title']);

        $this->assertSame(
            '<p>First article body.</p>',
            $reply->message['content'],
            'The reply body must be the first article, never the raw JSON'
        );
    }

    /**
     * A photo filed as a child of the burst head — the shape a WhatsApp album takes.
     */
    private function attachPhotoChild(Message $head, MessageType $messageType): Message
    {
        $child = Message::factory()
            ->withAppId($head->apps_id)
            ->withCompanyId($head->companies_id)
            ->withMessageType($messageType)
            ->create([
                'parent_id' => $head->getId(),
                'message' => [
                    'content' => '',
                    'from_me' => false,
                    'from_ia' => false,
                    'chat_jid' => self::GROUP_JID,
                    'conversation_type' => 'group',
                ],
                'is_locked' => 0,
            ]);

        $this->channel->addMessage($child);

        $file = new FilesystemServices($head->app, $head->company)->createFileSystemFromBase64(
            base64_encode(file_get_contents(__DIR__ . '/../../../../public/favicon.ico') ?: 'x'),
            'burst-photo.png',
            auth()->user()
        );

        $child->addFile($file, 'burst-photo.png');

        return $child;
    }

    private function latestAgentMessage(): ?Message
    {
        return $this->channel->messages()
            ->orderBy('messages.id', 'desc')
            ->get()
            ->first(fn (Message $m): bool => (bool) ($m->message['from_ia'] ?? false));
    }

    private function runBurst(
        bool $shouldReply,
        bool $withPhotoChild = false,
        string $handler = StructuredNeuronAgentStub::class
    ): array {
        // The WaSender client is Guzzle-backed, so this only neutralises anything on the Http
        // facade; the send itself is asserted through the `replied` flag and the tag.
        Http::fake();

        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'whatsapp'],
            ['name' => 'WhatsApp']
        );

        $this->channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => SessionChannelService::createChannelSlug('whatsapp-group', self::GROUP_JID),
            ],
            ['name' => 'Grupo de Prensa', 'description' => 'Test group channel', 'users_id' => $user->getId()]
        );

        $head = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => [
                    'content' => 'Rafael Zapata: The education ministry delivered 12 new classrooms in El Seibo this morning.',
                    'from_me' => false,
                    'from_ia' => false,
                    'chat_jid' => self::GROUP_JID,
                    'group_jid' => self::GROUP_JID,
                    'conversation_type' => 'group',
                ],
                'is_locked' => 0,
            ]);

        $this->channel->addMessage($head);

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Newsroom (Structured Test)',
                'provider' => 'neuron',
                'handler' => $handler,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Newsroom',
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
                'role' => [],
            ]);

        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $this->channel,
                'entity_namespace' => Channel::class,
                'entity_id' => (string) $this->channel->getId(),
                'canal_id' => SessionChannelService::createCanalId('whatsapp-group', self::GROUP_JID),
                'user' => [
                    'name' => $this->channel->name,
                    'id' => $this->channel->getId(),
                    'email' => null,
                ],
                'agent' => $agent,
            ])
        )->execute();

        self::$sent = [];

        $responder = new class ($this->channel, $head, $agent, $session) extends AgentBurstResponderAction {
            protected function sendText(string $to, string $text): void
            {
                AgentBurstResponderActionTest::$sent[] = ['to' => $to, 'text' => $text];
            }
        };

        $burstIds = [$head->getId()];

        if ($withPhotoChild) {
            $burstIds[] = $this->attachPhotoChild($head, $messageType)->getId();
        }

        return $responder->execute([
            'prompt' => 'Rafael Zapata: The education ministry delivered 12 new classrooms in El Seibo this morning.',
            'group_jid' => self::GROUP_JID,
            'should_reply' => $shouldReply,
            'burst_message_ids' => $burstIds,
        ]);
    }

    private Channel $channel;

    /**
     * What the responder would have put on the wire, captured by the subclass above.
     *
     * @var list<array{to: string, text: string}>
     */
    public static array $sent = [];
}
