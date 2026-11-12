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
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Jobs\FlushMessageBurstJob;
use Kanvas\Social\Messages\Models\Message;
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

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $channel = Channel::query()->findOrFail($result[0]['channel_id']);
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

    public function testANumberAnotherWorkspaceClaimsFilesNothingHere(): void
    {
        $this->claim($this->connection('fake', '+18095550700'), $this->otherCompany()->getId());

        $result = $this->receive($this->inbound('+18095550705', 'wamid.5', 'Hola'));

        $this->assertFalse($result[0]['filed']);
        $this->assertSame('claimed_by_another_workspace', $result[0]['reason']);
        $this->assertNothingFiledFor('+18095550705');
    }

    public function testAnAppWideReceiverFilesForTheWorkspaceThatClaimsTheNumber(): void
    {
        config(['communication.fake_shares_app_receiver' => true]);
        $this->receiver->forceFill(['companies_id' => $this->otherCompany()->getId()])->saveOrFail();
        $connection = $this->connection('fake', '+18095550700');
        $this->claim($connection);

        $result = $this->receive($this->inbound('+18095550706', 'wamid.6', 'Hola'));

        $message = Message::query()->findOrFail($result[0]['message_id']);
        $this->assertSame($this->currentCompany()->getId(), (int) $message->companies_id);
        $this->assertSame($this->currentCompany()->getId(), (int) $message->entity()->companies_id);
        $this->assertSame($connection->getId(), $message->message['communication']['connection_id']);
    }

    public function testAnUnclaimedNumberOnAnAppWideReceiverFilesNothing(): void
    {
        config(['communication.fake_shares_app_receiver' => true]);

        $result = $this->receive($this->inbound('+18095550707', 'wamid.7', 'Hola'));

        $this->assertFalse($result[0]['filed']);
        $this->assertSame('identity_not_connected', $result[0]['reason']);
        $this->assertNothingFiledFor('+18095550707');
    }

    public function testARedeliveryUpdatesRatherThanFilesASecondCopy(): void
    {
        $first = $this->receive($this->inbound('+18095550702', 'wamid.2', 'Hola'));
        $second = $this->receive($this->inbound('+18095550702', 'wamid.2', 'Hola'));

        $this->assertSame($first[0]['message_id'], $second[0]['message_id']);
    }

    public function testAStopIsFiledButNeverAnswered(): void
    {
        $result = $this->receive($this->inbound('+18095550703', 'wamid.3', 'STOP'));

        $this->assertTrue($result[0]['automated_response_suppressed']);
        Queue::assertNotPushed(FlushMessageBurstJob::class);
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
        $this->assertSame(1, Message::query()->findOrFail($result[0]['message_id'])->files()->count());
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

        $this->assertSame($known->getId(), Message::query()->findOrFail($onFile[0]['message_id'])->entity(People::class)->getId());

        $person = Message::query()->findOrFail($new[0]['message_id'])->entity(People::class);

        $this->assertInstanceOf(People::class, $person);
        $this->assertSame('Bob Ruiz', $person->firstname);
        $this->assertSame('bob@example.com', $person->contacts()->where('contacts_types_id', ContactTypeEnum::EMAIL->value)->value('value'));
        $this->assertInstanceOf(Lead::class, Message::query()->findOrFail($new[0]['message_id'])->entity(Lead::class));
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
        ?string $name = null
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
            raw: ['id' => $id, 'from_name' => $name]
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
