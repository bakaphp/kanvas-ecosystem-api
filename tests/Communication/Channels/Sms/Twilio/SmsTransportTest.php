<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\Sms\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\SmsTransport;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Mockery;
use Mockery\MockInterface;
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

    public function testANumberInAMessagingServiceSendsThroughTheService(): void
    {
        $delivery = $this->queuedDelivery(['config' => ['messaging_service_sid' => 'MG123']]);
        $this->messages->shouldReceive('create')
            ->once()
            ->with('+18095550199', ['messagingServiceSid' => 'MG123', 'body' => 'Hello'])
            ->andReturn((object) ['sid' => 'SM101']);

        $this->assertSame('SM101', $this->transport()->send($delivery, Content::text('Hello'))->externalId);
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

        return new class ($client) extends SmsTransport {
            public function __construct(
                private readonly TwilioClient $twilio
            ) {
            }

            protected function client(Delivery $delivery): TwilioClient
            {
                return $this->twilio;
            }
        };
    }
}
