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
use Kanvas\Guild\Customers\Models\People;
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
        $result = $this->receive(
            $this->inbound('+18095550704', 'wamid.4', 'Mira', [new InboundAttachment('https://vendor.test/media/1', 'image/jpeg')])
        );

        $this->assertSame(['https://vendor.test/media/1'], $this->source->downloaded);
        $this->assertSame(1, Message::query()->findOrFail($result[0]['message_id'])->files()->count());
    }

    public function testEmailIsNotReceivedHereYet(): void
    {
        $this->expectException(LogicException::class);

        $this->receive(new InboundMessage(
            provider: 'fake',
            channel: ChannelTypeEnum::EMAIL,
            inboundIdentity: 'inbox@example.com',
            from: 'customer@example.com',
            conversationSlug: 'fake-email-customer',
            messageVerb: 'fake-email',
            text: 'Hello'
        ));
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

    private function receive(InboundMessage $inbound): array
    {
        return new ReceiveMessageAction($this->receiver, $inbound, $this->source)->execute();
    }
}
