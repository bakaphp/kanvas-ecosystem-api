<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Twilio\Actions\ApplyDestinationRemediationAction;
use Kanvas\Connectors\Twilio\SmsTransport;
use Kanvas\Guild\Customers\Actions\Communication\SendMessageToPeopleAction;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client as TwilioClient;

final class SmsTransportTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private MockInterface $messages;

    private object $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => ['type' => 'mobile'], 'lineStatus' => ['status' => 'active']];
    }

    public function testATextLeavesFromTheConnectionsNumber(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldReceive('create')
            ->once()
            ->with('+18095550199', ['from' => '+18095550100', 'body' => 'Hello'])
            ->andReturn((object) ['sid' => 'SM100']);

        $result = $this->transport()->send($delivery, Content::text('Hello'));

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $result->status);
        $this->assertSame('SM100', $result->externalId);
    }

    public function testAVoipNumberOnACarrierKnownToCarryTextsIsTexted(): void
    {
        $delivery = $this->queuedDelivery();
        $lines = [
            ['type' => 'nonFixedVoip', 'carrier_name' => 'Bandwidth.com CLEC, LLC - IL'],
            ['type' => 'nonFixedVoip', 'carrier_name' => 'Twilio - SMS/MMS'],
            ['type' => 'fixedVoip', 'carrier_name' => 'Enflick - 360 Networks'],
            ['type' => 'fixedVoip', 'carrier_name' => 'Some Local Exchange - SVR'],
            ['type' => 'nonFixedVoip', 'carrier_name' => 'Onvoy, LLC'],
            ['type' => 'nonFixedVoip', 'carrier_name' => 'Sinch Voice'],
            ['type' => 'nonFixedVoip', 'carrier_name' => 'Telnyx LLC'],
            ['type' => 'fixedVoip', 'carrier_name' => 'Peerless Network of Texas'],
            ['type' => 'fixedVoip', 'carrier_name' => 'Level 3 Communications, LLC'],
            // The carrier of a mobile is not looked at.
            ['type' => 'mobile', 'carrier_name' => 'Comcast'],
        ];
        $this->messages->shouldReceive('create')->times(count($lines))->andReturn((object) ['sid' => 'SM-voip']);

        foreach ($lines as $intelligence) {
            $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => $intelligence, 'lineStatus' => ['status' => 'active']];

            $this->assertSame('SM-voip', $this->transport()->send($delivery, Content::text('Hello'))->externalId);
        }
    }

    /**
     * The reason is what the person who wrote the text reads, so it is that string and nothing more.
     */
    public function testAVoipNumberOnAnyOtherCarrierIsRefusedAsVoipWithoutSms(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldNotReceive('create');
        $lines = [
            ['type' => 'fixedVoip', 'carrier_name' => 'Comcast'],
            ['type' => 'nonFixedVoip', 'carrier_name' => 'Some VoIP Co'],
            ['type' => 'nonFixedVoip'],
            ['type' => 'fixedVoip', 'carrier_name' => '  '],
        ];

        foreach ($lines as $intelligence) {
            $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => $intelligence, 'lineStatus' => ['status' => 'active']];
            $result = $this->transport()->send($delivery, Content::text('Hello'));

            $this->assertSame('voip_without_sms', $result->errorCode);
            $this->assertSame('VOIP without SMS', $result->error);
        }
    }

    /**
     * The mark a carrier failure leaves on the number is what the next text is refused on, before
     * the lookup is paid for.
     */
    #[DataProvider('codesThatMarkANumberInvalid')]
    public function testANumberWhoseDeliveryFailedAcceptsNoMoreTexts(string $errorCode): void
    {
        $delivery = $this->queuedDelivery();
        $people = $delivery->request->people;
        $email = $people->addEmail('still-reachable-' . $people->getId() . '@example.com');
        $this->messages->shouldReceive('create')->once()->andReturn((object) ['sid' => 'SM-before']);

        $transport = $this->transport();
        $this->assertSame('SM-before', $transport->send($delivery, Content::text('Hello'))->externalId);
        $this->assertSame(1, $transport->lookups);

        new ApplyDestinationRemediationAction(
            $delivery->app,
            $delivery->company,
            '+18095550199',
            $errorCode
        )->execute();

        $transport = $this->transport();
        $result = $transport->send($delivery->fresh(), Content::text('Hello again'));

        $this->assertSame('undeliverable_number', $result->errorCode);
        $this->assertSame(
            '+18095550199 is marked invalid; no further texts are sent to it.',
            $result->error
        );
        $this->assertSame(0, $transport->lookups);

        $this->assertTrue($email->fresh()->isDeliverable(), 'the dead number says nothing about their email');
        $this->assertSame(
            $email->value,
            new SendMessageToPeopleAction($people->fresh())->addressFor(ChannelTypeEnum::EMAIL)
        );
    }

    public static function codesThatMarkANumberInvalid(): array
    {
        return [
            'unreachable handset' => ['30003'],
            'unknown destination' => ['30005'],
            'landline or unreachable carrier' => ['30006'],
        ];
    }

    /**
     * Allow-list: anything Twilio does not call mobile or VoIP is refused before the Messages API is called.
     */
    public function testOnlyAMobileOrVoipNumberIsTexted(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldNotReceive('create');

        $lines = [['type' => 'tollFree'], ['type' => ''], ['type' => null, 'error_code' => 60600], []];

        foreach ($lines as $intelligence) {
            $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => $intelligence, 'lineStatus' => ['status' => 'active']];

            $this->assertSame('not_mobile', $this->transport()->send($delivery, Content::text('Hello'))->errorCode);
        }

        $this->line = (object) ['valid' => false, 'lineTypeIntelligence' => [], 'lineStatus' => []];
        $this->assertSame('invalid_number', $this->transport()->send($delivery, Content::text('Hello'))->errorCode);

        $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => ['type' => 'mobile'], 'lineStatus' => ['status' => 'unreachable']];
        $this->assertSame('line_unreachable', $this->transport()->send($delivery, Content::text('Hello'))->errorCode);
    }

    public function testANumberAlreadyKnownToBeALandlineIsRefusedWithoutAnotherLookup(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldNotReceive('create');

        $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => ['type' => 'landline'], 'lineStatus' => ['status' => 'active']];
        $this->assertSame('not_mobile', $this->transport()->send($delivery, Content::text('Hello'))->errorCode);

        // What the lookup would say now is not asked: the person's contact already says landline.
        $this->line = (object) ['valid' => true, 'lineTypeIntelligence' => ['type' => 'mobile'], 'lineStatus' => ['status' => 'active']];
        $this->assertSame('not_mobile', $this->transport()->send($delivery, Content::text('Hello'))->errorCode);
    }

    public function testAReplyIsNotLookedUp(): void
    {
        $delivery = $this->queuedDelivery();
        $delivery->request->forceFill(['kind' => OperationKindEnum::REPLY])->saveOrFail();
        $this->line = (object) ['valid' => false, 'lineTypeIntelligence' => [], 'lineStatus' => []];
        $this->messages->shouldReceive('create')->once()->andReturn((object) ['sid' => 'SM-reply']);

        $this->assertSame('SM-reply', $this->transport()->send($delivery->fresh(), Content::text('Hello'))->externalId);
    }

    public function testANumberInAMessagingServiceSendsThroughTheService(): void
    {
        $delivery = $this->queuedDelivery(['config' => ['messaging_service_sid' => 'MG123']]);
        $this->messages->shouldReceive('create')
            ->once()
            ->with('+18095550199', ['messagingServiceSid' => 'MG123', 'body' => 'Hello'])
            ->andReturn((object) ['sid' => 'SM101']);

        $this->assertSame('SM101', $this->transport()->send($delivery, Content::text('Hello'))->externalId);
    }

    public function testZeroIsSentAsText(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldReceive('create')
            ->once()
            ->withArgs(fn (string $to, array $payload): bool => ($payload['body'] ?? null) === '0')
            ->andReturn((object) ['sid' => 'SM-zero']);

        $result = $this->transport()->send($delivery, Content::text('0'));

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $result->status);
        $this->assertSame('SM-zero', $result->externalId);
    }

    public function testAttachmentsGoOutAsMedia(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldReceive('create')
            ->once()
            ->with('+18095550199', [
                'from' => '+18095550100',
                'body' => 'Photos',
                'mediaUrl' => ['https://cdn.example.com/a.jpg'],
            ])
            ->andReturn((object) ['sid' => 'SM102']);

        $content = Content::text('Photos')->withAttachments(['https://cdn.example.com/a.jpg']);

        $this->assertSame([], $this->transport()->send($delivery, $content)->warnings);
    }

    public function testMoreAttachmentsThanATextCarriesFailWhenRequiredAndAreTrimmedWhenNot(): void
    {
        $delivery = $this->queuedDelivery();
        $urls = array_map(fn (int $index): string => "https://cdn.example.com/{$index}.jpg", range(1, 11));
        $this->messages->shouldReceive('create')
            ->once()
            ->withArgs(fn (string $to, array $payload): bool => count($payload['mediaUrl']) === 10)
            ->andReturn((object) ['sid' => 'SM103']);

        $required = $this->transport()->send($delivery, Content::text('Photos')->withAttachments($urls, required: true));
        $optional = $this->transport()->send($delivery, Content::text('Photos')->withAttachments($urls));

        $this->assertSame('too_many_attachments', $required->errorCode);
        $this->assertSame(['attachments_truncated'], $optional->warnings);
    }

    public function testATextOverTheCarrierLimitIsRefusedBeforeTwilioIsCalled(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldNotReceive('create');

        $result = $this->transport()->send($delivery, Content::text(str_repeat('a', 1601)));

        $this->assertSame(DeliveryStatusEnum::FAILED, $result->status);
        $this->assertSame('content_too_long', $result->errorCode);
    }

    public function testARefusalFromTwilioIsAFailureAndAnUnsubscribedRecipientIsFlagged(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldReceive('create')
            ->twice()
            ->andThrowExceptions([
                new RestException('Attempt to send to unsubscribed recipient', 21610, 400),
                new RestException("The 'To' number is not a valid phone number", 21211, 400),
            ]);

        $unsubscribed = $this->transport()->send($delivery, Content::text('Hello'));
        $invalid = $this->transport()->send($delivery, Content::text('Hello'));

        $this->assertSame('21610', $unsubscribed->errorCode);
        $this->assertTrue($unsubscribed->recipientOptedOut);
        $this->assertSame('21211', $invalid->errorCode);
        $this->assertFalse($invalid->recipientOptedOut);
    }

    /**
     * Only an answer from Twilio is an outcome. A dropped connection is not one, and has to reach
     * the dispatcher as-is so the delivery is recorded as unknown.
     */
    public function testAnythingOtherThanATwilioAnswerIsNotTranslatedIntoAFailure(): void
    {
        $delivery = $this->queuedDelivery();
        $this->messages->shouldReceive('create')->andThrow(new RuntimeException('cURL error 28: timed out'));

        $this->expectException(RuntimeException::class);

        $this->transport()->send($delivery, Content::text('Hello'));
    }

    public function testLookupReportsWhatTwilioNowSaysAboutTheMessage(): void
    {
        $delivery = $this->queuedDelivery();
        $delivery->forceFill(['external_id' => 'SM104'])->save();

        $context = Mockery::mock();
        $context->shouldReceive('fetch')->andReturn((object) [
            'sid' => 'SM104',
            'status' => 'undelivered',
            'errorCode' => 30003,
            'errorMessage' => 'Unreachable destination handset',
        ]);

        $client = Mockery::mock(TwilioClient::class);
        $client->shouldReceive('messages')->with('SM104')->andReturn($context);

        $result = $this->transport($client)->lookup($delivery);

        $this->assertSame(DeliveryStatusEnum::FAILED, $result->status);
        $this->assertSame('undelivered', $result->providerStatus);
        $this->assertSame('30003', $result->errorCode);
    }

    private function queuedDelivery(array $connectionAttributes = []): Delivery
    {
        Queue::fake();
        $this->messages = Mockery::mock();
        $this->defaultSmsConnection('twilio', '+18095550100', $connectionAttributes);

        return $this->communication()
            ->send($this->personWithCellphone('+18095550199'), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);
    }

    private function transport(?TwilioClient $client = null): SmsTransport
    {
        if ($client === null) {
            $client = Mockery::mock(TwilioClient::class);
            $client->messages = $this->messages;
        }

        return new class ($client, $this->line) extends SmsTransport {
            public int $lookups = 0;

            public function __construct(
                private readonly TwilioClient $twilio,
                private readonly object $line
            ) {
            }

            protected function lineOf(Delivery $delivery, string $number): object
            {
                $this->lookups++;

                return $this->line;
            }

            protected function client(Delivery $delivery): TwilioClient
            {
                return $this->twilio;
            }
        };
    }
}
