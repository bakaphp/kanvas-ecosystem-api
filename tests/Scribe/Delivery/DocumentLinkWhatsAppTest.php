<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Connectors\WaSender\WhatsAppTransport;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Scribe\Delivery\DataTransferObject\DeliveryTarget;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentDelivery;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Delivery\Services\DocumentLinkSenderService;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * A document link sent by WhatsApp goes through the company's WhatsApp connection like every other
 * message to a person: on the ledger, with its delivery on the filed message, and not at all for a
 * company with no connection.
 */
final class DocumentLinkWhatsAppTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string OUR_NUMBER = '+18095550820';
    private const string CUSTOMER = '+18095550821';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'communication.providers' => [WaSenderHandler::class],
            'queue.default' => 'sync',
        ]);
    }

    public function testTheLinkAndItsPdfLeaveOnTheCompanysWhatsAppConnectionAndTheMessageCarriesTheDelivery(): void
    {
        $connection = $this->defaultConnection(ChannelTypeEnum::WHATSAPP, 'wasender', self::OUR_NUMBER);
        $person = $this->personWithCellphone(self::CUSTOMER);
        $sent = [];
        $this->bindWaSender($sent);

        $sender = new DocumentLinkSenderService();
        $target = $sender->resolveTarget($person, LeadCommunicationChannelEnum::WHATSAPP);

        $message = $sender->send(
            $target,
            $person,
            auth()->user(),
            'Here is your invoice: https://docs.kanvas.test/i/1',
            'Invoice 1',
            [new Filesystem()->forceFill(['url' => 'https://cdn.kanvas.test/invoice.pdf'])]
        );

        $this->assertSame(
            [
                ['document', '18095550821', 'https://cdn.kanvas.test/invoice.pdf'],
                ['text', '18095550821', 'Here is your invoice: https://docs.kanvas.test/i/1'],
            ],
            $sent
        );

        $this->assertEqualsCanonicalizing(
            [
                new WhatsAppTransport()->conversation($connection, self::CUSTOMER)->channelSlug,
                new PeopleChannelService()->slugFor($person),
            ],
            $message->channels()->pluck('slug')->all(),
            'filed on the channel the customer\'s answer arrives on, and on the person\'s whole conversation'
        );

        $delivery = Delivery::query()->where('message_id', $message->getId())->sole();
        $this->assertSame($connection->getId(), (int) $delivery->connection_id);
        $this->assertSame(ChannelTypeEnum::WHATSAPP, $delivery->channel);
        $this->assertSame('WA-TEXT', $delivery->external_id);
        $this->assertSame($person->getId(), (int) $delivery->request->people_id);
    }

    /**
     * A vendor that cannot carry a file sends the link alone. The agent that sent the document is
     * told, so it does not tell the customer a PDF is attached.
     */
    public function testTheAgentIsToldWhenTheChannelCouldNotCarryThePdf(): void
    {
        $delivery = fn (array $warnings): DocumentDelivery => new DocumentDelivery(
            engagement: new Engagement()->forceFill(['uuid' => 'engagement-uuid']),
            link: 'https://docs.kanvas.test/i/1',
            target: new DeliveryTarget(LeadCommunicationChannelEnum::WHATSAPP, self::CUSTOMER),
            message: new Message()->forceFill(['message' => ['raw_data' => ['warnings' => $warnings]]]),
        );

        $withoutThePdf = $delivery(['attachments_dropped'])->toToolResult();

        $this->assertFalse($withoutThePdf['pdf_attached']);
        $this->assertStringContainsString('could not carry the PDF', $withoutThePdf['note']);
        $this->assertArrayNotHasKey('pdf_attached', $delivery([])->toToolResult());
    }

    public function testACompanyWithNoWhatsAppConnectionIsToldSoBeforeAnythingIsSent(): void
    {
        $person = $this->personWithCellphone(self::CUSTOMER);

        try {
            new DocumentLinkSenderService()->resolveTarget($person, LeadCommunicationChannelEnum::WHATSAPP);
            $this->fail('A company with no WhatsApp connection has nowhere to send from.');
        } catch (DocumentDeliveryException $exception) {
            $this->assertSame(DocumentDeliveryException::WHATSAPP_UNAVAILABLE, $exception->reason);
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $sent filled with what WaSender was asked to send
     */
    private function bindWaSender(array &$sent): void
    {
        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendDocumentMessage')->andReturnUsing(
            function (string $to, string $url) use (&$sent): array {
                $sent[] = ['document', $to, $url];

                return ['data' => ['msgId' => 'WA-DOCUMENT']];
            }
        );
        $service->shouldReceive('sendTextMessage')->andReturnUsing(
            function (string $to, string $text) use (&$sent): array {
                $sent[] = ['text', $to, $text];

                return ['data' => ['msgId' => 'WA-TEXT']];
            }
        );

        $transport = new class ($service) extends WhatsAppTransport {
            public function __construct(private readonly MessageService $service)
            {
            }

            protected function messageService(Delivery $delivery): MessageService
            {
                return $this->service;
            }

            protected function pause(): void
            {
            }
        };

        $this->app->bind(WhatsAppTransport::class, fn () => $transport);
    }
}
