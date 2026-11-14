<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Communication\Messages\Actions\FlushInboundBurstAction;
use Kanvas\Communication\Messages\Actions\ReceiveMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundSenderPolicy;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Jobs\RespondToInboundMessageJob;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Jobs\FlushMessageBurstJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use LogicException;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeInbound;
use Tests\TestCase;

/**
 * The shared inbound pipeline, driven by a provider that is not Twilio on a medium that is not SMS:
 * nothing after the parse may depend on which vendor delivered the message.
 */
final class ReceiveMessageActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private ReceiverWebhook $receiver;

    private FakeInbound $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->source = $this->useFakeInbound();

        LeadType::firstOrCreate(
            ['apps_id' => $this->currentApp()->getId(), 'companies_id' => $this->currentCompany()->getId(), 'name' => 'Warm'],
            ['description' => 'Warm Lead Type', 'is_active' => true, 'uuid' => Str::uuid()]
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->currentApp()->getId())
            ->user(auth()->user()->getId())
            ->company($this->currentCompany()->getId())
            ->create([
                'action_id' => WorkflowAction::firstOrCreate(['model_name' => self::class], ['name' => 'Fake inbound'])->getId(),
                'configuration' => [],
            ]);
    }

    public function testAMessageOnAConnectionIsFiledInItsConversationAndQueuedAsOneTurn(): void
    {
        $connection = $this->connection('fake', '+18095550700');
        $this->claim($connection);

        $result = $this->receive($this->inbound('+18095550701', 'wamid.1', 'Hola, sigue disponible?'));

        $message = Message::query()->findOrFail($result['message_id']);
        $channel = Channel::query()->findOrFail($result['channel_id']);
        $person = $message->entity();
        $lead = $message->entity(Lead::class);

        $this->assertSame('fake-whatsapp-18095550701', $channel->slug);
        $this->assertInstanceOf(People::class, $person);
        $this->assertSame(People::class, $channel->entity_namespace);
        $this->assertSame((string) $person->getId(), (string) $channel->entity_id);
        $this->assertSame($person->getId(), $lead?->people_id);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
        $this->assertSame('whatsapp', $message->message['communication']['channel']);
        $this->assertSame('+18095550701', $message->message['communication']['thread']);
        $this->assertSame('fake-whatsapp', $message->messageType->verb);
        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame('whatsapp', $lead->get(LeadConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value));

        Queue::assertPushed(
            FlushMessageBurstJob::class,
            fn (FlushMessageBurstJob $job): bool => $job->handlerClass === FlushInboundBurstAction::class
                && $job->params === ['communication_channel' => 'whatsapp']
        );
    }

    public function testAClosedBurstQueuesTheReplyOnlyWhenAnAgentOwnsTheConnection(): void
    {
        $connection = $this->connection('fake', '+18095550700');
        $this->claim($connection);
        $result = $this->receive($this->inbound('+18095550711', 'wamid.20', 'Hola'));
        $channel = Channel::query()->findOrFail($result['channel_id']);
        $messages = Message::query()->whereKey($result['message_id'])->get();

        new FlushInboundBurstAction($this->currentApp(), $channel, $messages)->execute();
        Queue::assertNotPushed(RespondToInboundMessageJob::class);

        $connection->forceFill(['config' => ['agent_id' => $this->agent()->getId()]])->saveOrFail();

        new FlushInboundBurstAction($this->currentApp(), $channel, $messages)->execute();
        Queue::assertPushed(
            RespondToInboundMessageJob::class,
            fn (RespondToInboundMessageJob $job): bool => $job->message->is($messages->first()) && $job->burstText === 'Hola'
        );
    }

    public function testANumberAnotherWorkspaceClaimsFilesNothingHere(): void
    {
        $this->claim($this->connection('fake', '+18095550700'), $this->otherCompany()->getId());

        $result = $this->receive($this->inbound('+18095550705', 'wamid.5', 'Hola'));

        $this->assertFalse($result['filed']);
        $this->assertSame('claimed_by_another_workspace', $result['reason']);
        $this->assertNothingFiledFor('+18095550705');
    }

    public function testAnAppWideReceiverFilesForTheWorkspaceThatClaimsTheNumber(): void
    {
        config(['communication.fake_shares_app_receiver' => true]);
        $this->receiver->forceFill(['companies_id' => $this->otherCompany()->getId()])->saveOrFail();
        $connection = $this->connection('fake', '+18095550700');
        $this->claim($connection);

        $result = $this->receive($this->inbound('+18095550706', 'wamid.6', 'Hola'));

        $message = Message::query()->findOrFail($result['message_id']);
        $this->assertSame($this->currentCompany()->getId(), (int) $message->companies_id);
        $this->assertSame($this->currentCompany()->getId(), (int) $message->entity()->companies_id);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
    }

    public function testAnUnclaimedNumberOnAnAppWideReceiverFilesNothing(): void
    {
        config(['communication.fake_shares_app_receiver' => true]);

        $result = $this->receive($this->inbound('+18095550707', 'wamid.7', 'Hola'));

        $this->assertFalse($result['filed']);
        $this->assertSame('identity_not_connected', $result['reason']);
        $this->assertNothingFiledFor('+18095550707');
    }

    public function testARedeliveryFilesNothingAndStartsNoSecondAnswer(): void
    {
        $first = $this->receive($this->inbound('+18095550702', 'wamid.2', 'Hola'));
        $channel = Channel::query()->findOrFail($first['channel_id']);
        $messagesBefore = $channel->messages()->count();
        Queue::fake();

        $second = $this->receive($this->inbound('+18095550702', 'wamid.2', 'Hola'));

        $this->assertSame($first['message_id'], $second['message_id']);
        $this->assertTrue($second['redelivery']);
        $this->assertSame($messagesBefore, $channel->messages()->count());
        Queue::assertNothingPushed();
    }

    public function testAStopIsFiledButNeverAnswered(): void
    {
        $result = $this->receive($this->inbound('+18095550703', 'wamid.3', 'STOP'));

        $this->assertTrue($result['automated_response_suppressed']);
        Queue::assertNotPushed(FlushMessageBurstJob::class);
    }

    public function testAStopWordThatCouldMeanSomethingElseIsFlaggedForReview(): void
    {
        $result = $this->receive($this->inbound('+18095550713', 'wamid.13', 'Cancel'));

        $this->assertTrue($result['automated_response_suppressed']);
        $this->assertSame('ambiguous_keyword', $result['consent_match']);
    }

    public function testAFileTheProviderHoldsIsDownloadedWithItsCredentials(): void
    {
        $result = $this->receive($this->inbound(
            '+18095550704',
            'wamid.4',
            'Mira',
            [new InboundAttachment('https://vendor.test/media/1', 'image/jpeg')]
        ));

        $this->assertSame(['https://vendor.test/media/1'], $this->source->downloaded);
        $this->assertSame(1, Message::query()->findOrFail($result['message_id'])->files()->count());
    }

    /**
     * A download takes seconds. A part still unparented while it runs is adopted as head by the
     * next part, so it joins its burst first and the close is only armed once its files are in.
     */
    public function testAMessageJoinsItsBurstBeforeItsMediaIsFetched(): void
    {
        $source = new RecordingInbound($this->source);
        $first = new ReceiveMessageAction(
            $this->receiver,
            $this->inbound('+18095550714', 'wamid.14', 'Mira esto'),
            $source
        )->execute();

        $second = new ReceiveMessageAction(
            $this->receiver,
            $this->inbound(
                '+18095550714',
                'wamid.15',
                'Y esto',
                [new InboundAttachment('https://vendor.test/media/15', 'image/jpeg')]
            ),
            $source
        )->execute();

        $this->assertSame(
            [['parent_id' => $first['message_id'], 'closes_armed' => 1]],
            $source->atDownload,
            'chained onto the first part, with only that part\'s close armed, when the download ran'
        );
        $this->assertSame(1, Message::query()->findOrFail($second['message_id'])->files()->count());
        $this->assertCount(2, Queue::pushed(
            FlushMessageBurstJob::class,
            fn (FlushMessageBurstJob $job): bool => $job->burstHeadId === $first['message_id']
        ));
    }

    /**
     * A medium with real threading has no flurry to collapse: the turn is announced as it arrives,
     * and the agent that owns the connection is queued at once.
     */
    public function testAMediumWithoutBurstsIsAnnouncedAsItArrives(): void
    {
        $connection = $this->connection('fake', '+18095550700');
        $this->claim($connection);
        $connection->forceFill(['config' => ['agent_id' => $this->agent()->getId()]])->saveOrFail();
        $source = new RecordingInbound($this->source, bursts: false);

        $result = new ReceiveMessageAction(
            $this->receiver,
            $this->inbound('+18095550715', 'wamid.16', 'Hola'),
            $source
        )->execute();

        Queue::assertNotPushed(FlushMessageBurstJob::class);
        Queue::assertPushed(
            RespondToInboundMessageJob::class,
            fn (RespondToInboundMessageJob $job): bool => $job->message->getId() === $result['message_id']
                && $job->burstText === null
        );
    }

    public function testOurOwnSideOfAConversationIsNeverAnswered(): void
    {
        $connection = $this->connection('fake', '+18095550700');
        $this->claim($connection);
        $connection->forceFill(['config' => ['agent_id' => $this->agent()->getId()]])->saveOrFail();

        $result = $this->receive(new InboundMessage(
            provider: 'fake',
            channel: ChannelTypeEnum::WHATSAPP,
            inboundIdentity: '+18095550700',
            from: '+18095550716',
            providerMessageId: 'wamid.17',
            conversationSlug: 'fake-whatsapp-18095550716',
            messageVerb: 'fake-whatsapp',
            messageSlug: 'fake-wamid-17',
            text: 'Typed on the phone',
            fromUs: true
        ));

        $this->assertTrue($result['is_from_me']);
        Queue::assertNotPushed(FlushMessageBurstJob::class);
        Queue::assertNotPushed(RespondToInboundMessageJob::class);
    }

    public function testEveryMessageOfACounterpartLandsInOneConversation(): void
    {
        $first = $this->receive($this->inbound('+18095550717', 'wamid.18', 'Hola'));
        $second = $this->receive($this->inbound('+18095550717', 'wamid.19', 'Sigue disponible?'));

        $this->assertSame($first['channel_id'], $second['channel_id']);
        $this->assertSame(1, Channel::query()->where('slug', 'fake-whatsapp-18095550717')->count());
    }

    /**
     * A session that stands in for someone else's number says nothing about the person filed: who
     * they are stays as it is on file.
     */
    public function testAPersonKeptAsFoundTakesNothingFromTheMessage(): void
    {
        $kept = $this->personWithCellphone('+18095550718');
        $updated = $this->personWithCellphone('+18095550719');

        $this->receive($this->standIn('+18095550718', 'wamid.21', keepsPersonAsFound: true));
        $this->receive($this->standIn('+18095550719', 'wamid.22', keepsPersonAsFound: false));

        $this->assertNull($kept->fresh()->get('fake_session'));
        $this->assertSame('wamid.22', $updated->fresh()->get('fake_session'));
    }

    /**
     * Email names its sender: the person behind the address is found by it, and a new one is
     * created with the address as an email contact and the name the mail carried.
     */
    public function testEmailIsFiledOnThePersonBehindTheAddress(): void
    {
        $known = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['firstname' => 'Ana']);
        $known->contacts()->create(['contacts_types_id' => ContactTypeEnum::EMAIL->value, 'value' => 'ana@example.com', 'weight' => 0]);

        $onFile = $this->receive($this->email('Ana@Example.com', 'e-1', 'Hello'));
        $new = $this->receive($this->email(
            'bob@example.com',
            'e-2',
            'Hi there',
            name: 'Bob Ruiz'
        ));

        $this->assertSame($known->getId(), Message::query()->findOrFail($onFile['message_id'])->entity(People::class)->getId());

        $person = Message::query()->findOrFail($new['message_id'])->entity(People::class);

        $this->assertInstanceOf(People::class, $person);
        $this->assertSame('Bob Ruiz', $person->firstname);
        $this->assertSame('bob@example.com', $person->contacts()->where('contacts_types_id', ContactTypeEnum::EMAIL->value)->value('value'));
        $this->assertInstanceOf(Lead::class, Message::query()->findOrFail($new['message_id'])->entity(Lead::class));
    }

    /**
     * An agent's own mailbox: a colleague writes as themselves, which makes no person and no lead.
     */
    public function testAColleagueWritingToAnAgentsMailboxIsFiledAsThemselves(): void
    {
        $result = $this->receive($this->email(
            auth()->user()->email,
            'e-10',
            'Can you pull last month\'s invoices?',
            policy: InboundSenderPolicy::assistant(acceptsStrangers: false)
        ));

        $message = Message::query()->findOrFail($result['message_id']);
        $channel = Channel::query()->findOrFail($result['channel_id']);

        $this->assertSame(auth()->user()->getId(), $message->entity(Users::class)?->getId());
        $this->assertNull($message->entity(People::class));
        $this->assertNull($message->entity(Lead::class));
        $this->assertSame(Users::class, $channel->entity_namespace);
    }

    public function testAStrangerIsTurnedAwayFromAMailboxThatOnlyTakesKnownSenders(): void
    {
        $result = $this->receive($this->email(
            'stranger@nowhere.test',
            'e-11',
            'Hello',
            policy: InboundSenderPolicy::assistant(acceptsStrangers: false)
        ));

        $this->assertFalse($result['filed']);
        $this->assertSame('unknown_sender', $result['reason']);
        $this->assertFalse(PeoplesRepository::getByAnyContact($this->currentApp(), $this->currentCompany(), emails: ['stranger@nowhere.test'])->exists());
    }

    /**
     * An open mailbox lets a stranger in as a person; an agent's address is not a lead form.
     */
    public function testAnOpenMailboxFilesAStrangerAsAPersonWithoutALead(): void
    {
        $result = $this->receive($this->email(
            'newcomer@nowhere.test',
            'e-12',
            'Hello',
            policy: InboundSenderPolicy::assistant(acceptsStrangers: true)
        ));

        $message = Message::query()->findOrFail($result['message_id']);

        $this->assertInstanceOf(People::class, $message->entity(People::class));
        $this->assertNull($message->entity(Lead::class));
    }

    public function testAChannelWithoutAnAddressBookIsNotReceivedHereYet(): void
    {
        $this->expectException(LogicException::class);

        $this->receive(new InboundMessage(
            provider: 'fake',
            channel: ChannelTypeEnum::SLACK,
            inboundIdentity: 'C123',
            from: 'U456',
            conversationSlug: 'fake-slack-u456',
            messageVerb: 'fake-slack',
            text: 'Hello'
        ));
    }

    private function email(
        string $from,
        string $id,
        string $text,
        ?string $name = null,
        ?InboundSenderPolicy $policy = null
    ): InboundMessage {
        return new InboundMessage(
            provider: 'fake',
            channel: ChannelTypeEnum::EMAIL,
            inboundIdentity: 'inbox@example.com',
            from: $from,
            providerMessageId: $id,
            conversationSlug: 'fake-email-' . Str::slug($from),
            messageVerb: 'fake-email',
            messageSlug: 'fake-' . $id,
            text: $text,
            raw: ['id' => $id],
            senderPolicy: $policy,
            fromName: $name
        );
    }

    /**
     * @param list<InboundAttachment> $attachments
     */
    private function inbound(
        string $from,
        string $id,
        string $text,
        array $attachments = []
    ): InboundMessage {
        return new InboundMessage(
            provider: 'fake',
            channel: ChannelTypeEnum::WHATSAPP,
            inboundIdentity: '+18095550700',
            from: $from,
            providerMessageId: $id,
            conversationSlug: 'fake-whatsapp-' . preg_replace('/\D/', '', $from),
            messageVerb: 'fake-whatsapp',
            messageSlug: 'fake-' . Str::slug($id . '-' . $from),
            text: $text,
            raw: ['id' => $id],
            attachments: $attachments
        );
    }

    private function standIn(string $from, string $id, bool $keepsPersonAsFound): InboundMessage
    {
        return new InboundMessage(
            provider: 'fake',
            channel: ChannelTypeEnum::WHATSAPP,
            inboundIdentity: '+18095550700',
            from: $from,
            providerMessageId: $id,
            conversationSlug: 'fake-whatsapp-' . preg_replace('/\D/', '', $from),
            messageVerb: 'fake-whatsapp',
            messageSlug: 'fake-' . Str::slug($id . '-' . $from),
            text: 'Hola',
            personCustomFields: ['fake_session' => $id],
            senderPolicy: InboundSenderPolicy::customers(keepsPersonAsFound: $keepsPersonAsFound)
        );
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();
    }

    private function assertNothingFiledFor(string $from): void
    {
        $this->assertFalse(
            PeoplesRepository::getByPhoneNumber($this->currentApp(), $this->currentCompany(), [$from])->exists(),
            'no person is created for a message no workspace here owns'
        );
        $this->assertFalse(Channel::query()->where('slug', 'fake-whatsapp-' . preg_replace('/\D/', '', $from))->exists());
        Queue::assertNotPushed(FlushMessageBurstJob::class);
    }

    private function receive(InboundMessage $inbound): array
    {
        return new ReceiveMessageAction($this->receiver, $inbound, $this->source)->execute();
    }
}
