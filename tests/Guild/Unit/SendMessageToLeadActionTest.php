<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

use Illuminate\Support\Facades\Exceptions;
use Kanvas\Connectors\RespondIO\Client as RespondIOClient;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum as RespondIOConfigurationEnum;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Exceptions\LeadOptedOutException;
use Kanvas\Guild\Leads\Models\Lead;
use Mockery;
use Tests\TestCaseUnit;
use Twilio\Exceptions\RestException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Http\Response;
use Twilio\Rest\Client as TwilioClient;

final class SendMessageToLeadActionTest extends TestCaseUnit
{
    public function testTwilioSenderPrecheckRejectsMissingRouteBeforeApiCall(): void
    {
        $action = $this->makeTwilioPrecheckAction([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('21603');

        $action->validateTwilioSenderRouteForTest('');
    }

    public function testTwilioSenderPrecheckRejectsCrossAccountRoute(): void
    {
        $action = $this->makeTwilioPrecheckAction([
            TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value => 'ACdealer',
            TwilioConfigurationEnum::TWILIO_SENDER_ACCOUNT_SID->value => 'ACother',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('21660');

        $action->validateTwilioSenderRouteForTest('+12722917870');
    }

    public function testTwilioSenderPrecheckUsesConfiguredMessagingService(): void
    {
        $action = $this->makeTwilioPrecheckAction([
            TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value => 'ACdealer',
            TwilioConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID->value => 'MG123',
            TwilioConfigurationEnum::TWILIO_A2P_REGISTRATION_STATUS->value => 'approved',
        ]);

        $this->assertSame(
            ['messagingServiceSid' => 'MG123'],
            $action->validateTwilioSenderRouteForTest(''),
        );
        $action->guardSmsDestinationForTest('+12722917870', null);
    }

    public function testTwilioSenderPrecheckRejectsUnregisteredA2pRoute(): void
    {
        $action = $this->makeTwilioPrecheckAction([
            TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value => 'ACdealer',
            TwilioConfigurationEnum::TWILIO_ALLOWED_FROM_PHONE_NUMBERS->value => ['+12722917870'],
            TwilioConfigurationEnum::TWILIO_ENFORCE_A2P_REGISTRATION->value => true,
            TwilioConfigurationEnum::TWILIO_A2P_REGISTRATION_STATUS->value => 'pending',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('30034');

        $action->validateTwilioSenderRouteForTest('+1 (272) 291-7870');
    }

    public function testTwilioBodyPrecheckUsesConfiguredCarrierSafeLimit(): void
    {
        $action = $this->makeTwilioPrecheckAction([
            TwilioConfigurationEnum::TWILIO_MAX_MESSAGE_BODY_LENGTH->value => 10,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('30019');

        $action->validateTwilioMessageBodyForTest('eleven chars');
    }

    public function testSmsResolvesConfiguredCompanySenderWhenCallerPassesNull(): void
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')
            ->once()
            ->with(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ->andReturn('+12722917870');

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $action = new class ($lead) extends SendMessageToLeadAction {
            public string $capturedFrom = '';

            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                $this->capturedFrom = $from;

                return [];
            }
        };

        $action->execute(
            channel: 'sms',
            message: 'First reach-out',
            from: null,
            to: '+15551234567',
        );

        $this->assertSame('+12722917870', $action->capturedFrom);
    }

    private function makeTwilioPrecheckAction(array $configuration): SendMessageToLeadAction
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static fn (string $key) => $configuration[$key] ?? null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn(null);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        return new class ($lead) extends SendMessageToLeadAction {
            public function validateTwilioSenderRouteForTest(string $from): array
            {
                return $this->validateTwilioSenderRoute($from);
            }

            public function validateTwilioMessageBodyForTest(string $message): void
            {
                $this->validateTwilioMessageBody($message);
            }

            public function guardSmsDestinationForTest(string $cellphone, ?string $from): void
            {
                $this->guardSmsDestination($cellphone, $from);
            }
        };
    }

    public function testTwilioMediaUrlsIncludeDocuments(): void
    {
        $app = Mockery::mock();
        $app->shouldReceive('get')
            ->with(TwilioConfigurationEnum::TWILIO_MMS_MAX_TOTAL_MEDIA->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn(null);
        $lead->shouldReceive('getAttribute')->with('app')->andReturn($app);

        $action = new class ($lead) extends SendMessageToLeadAction {
            public function setProcessedFilesForTest(array $processedFiles): void
            {
                $this->processedFiles = $processedFiles;
            }

            public function setVideoEngagementsForTest(array $videoEngagements): void
            {
                $this->videoEngagements = $videoEngagements;
            }

            public function getMediaUrlsForTwilioForTest(): array
            {
                return $this->getMediaUrlsForTwilio();
            }
        };

        $action->setVideoEngagementsForTest([
            ['gif_url' => 'https://cdn.example.com/video-preview.gif'],
        ]);

        $action->setProcessedFilesForTest([
            [
                'url' => 'https://cdn.example.com/image.jpg',
                'type' => MediaTypeEnum::IMAGE,
            ],
            [
                'url' => 'https://cdn.example.com/voice.mp3',
                'type' => MediaTypeEnum::AUDIO,
            ],
            [
                'url' => 'https://cdn.example.com/contract.pdf',
                'type' => MediaTypeEnum::DOCUMENT,
            ],
        ]);

        $this->assertSame([
            'https://cdn.example.com/video-preview.gif',
            'https://cdn.example.com/image.jpg',
            'https://cdn.example.com/voice.mp3',
            'https://cdn.example.com/contract.pdf',
        ], $action->getMediaUrlsForTwilioForTest());
    }

    public function testIsRespondIoEnabledReturnsFalseWhenSettingMissing(): void
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')
            ->with(RespondIOConfigurationEnum::ENABLED->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $action = $this->makeAction($lead);

        $this->assertFalse($action->isRespondIoEnabledForTest());
    }

    public function testIsRespondIoEnabledReturnsTrueWhenSettingIsTrue(): void
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')
            ->with(RespondIOConfigurationEnum::ENABLED->value)
            ->andReturn(true);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $action = $this->makeAction($lead);

        $this->assertTrue($action->isRespondIoEnabledForTest());
    }

    public function testSendRespondIoMessageThrowsWhenLeadHasNoPhone(): void
    {
        $cellphones = Mockery::mock();
        $cellphones->shouldReceive('first')->andReturn(null);

        $people = Mockery::mock();
        $people->shouldReceive('getCellPhones')->andReturn($cellphones);

        $company = Mockery::mock();
        $company->shouldReceive('get')->with('allow_session_hijack', false)->andReturn(false);
        $company->shouldReceive('get')
            ->with(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn($people);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $client = Mockery::mock(RespondIOClient::class);
        $action = $this->makeAction($lead, $client);

        $this->expectException(LeadMissingContactException::class);
        $action->sendRespondIoMessageForTest('hello');
    }

    public function testSendRespondIoMessageSendsTextBody(): void
    {
        $client = Mockery::mock(RespondIOClient::class);
        $client->shouldReceive('sendMessage')
            ->once()
            ->with('+15551234567', 'hello world')
            ->andReturn(['ok' => true]);

        $action = $this->makeAction($this->makeLead(), $client);

        $result = $action->sendRespondIoMessageForTest('hello world', '+15551234567');

        $this->assertSame('respondio', $result['channel']);
        $this->assertSame('+15551234567', $result['to']);
        $this->assertCount(1, $result['messages']);
    }

    public function testSendRespondIoMessageSendsVideoEngagementsAttachmentsAndTextInOrder(): void
    {
        $client = Mockery::mock(RespondIOClient::class);

        $client->shouldReceive('sendMessage')
            ->once()
            ->with('+15551234567', 'https://cdn.example.com/engagement-1')
            ->ordered()
            ->andReturn(['id' => 'engagement-1']);

        $client->shouldReceive('sendAttachment')
            ->once()
            ->with('+15551234567', 'image', 'https://cdn.example.com/image.jpg')
            ->ordered()
            ->andReturn(['id' => 'image']);

        $client->shouldReceive('sendAttachment')
            ->once()
            ->with('+15551234567', 'audio', 'https://cdn.example.com/voice.mp3')
            ->ordered()
            ->andReturn(['id' => 'audio']);

        $client->shouldReceive('sendAttachment')
            ->once()
            ->with('+15551234567', 'file', 'https://cdn.example.com/contract.pdf')
            ->ordered()
            ->andReturn(['id' => 'file']);

        $client->shouldReceive('sendMessage')
            ->once()
            ->with('+15551234567', 'final body')
            ->ordered()
            ->andReturn(['id' => 'body']);

        $action = $this->makeAction($this->makeLead(), $client);

        $action->setVideoEngagementsForTest([
            ['url' => 'https://cdn.example.com/engagement-1'],
            ['url' => ''],
        ]);

        $action->setProcessedFilesForTest([
            ['is_processed_video' => true, 'video' => [], 'gif' => []],
            ['url' => 'https://cdn.example.com/image.jpg', 'type' => MediaTypeEnum::IMAGE],
            ['url' => 'https://cdn.example.com/voice.mp3', 'type' => MediaTypeEnum::AUDIO],
            ['url' => 'https://cdn.example.com/contract.pdf', 'type' => MediaTypeEnum::DOCUMENT],
        ]);

        $result = $action->sendRespondIoMessageForTest('final body', '+15551234567');

        $this->assertCount(5, $result['messages']);
    }

    public function testSendRespondIoMessageSkipsTextWhenBodyIsEmpty(): void
    {
        $client = Mockery::mock(RespondIOClient::class);
        $client->shouldNotReceive('sendMessage');
        $client->shouldReceive('sendAttachment')
            ->once()
            ->with('+15551234567', 'image', 'https://cdn.example.com/image.jpg')
            ->andReturn(['id' => 'image']);

        $action = $this->makeAction($this->makeLead(), $client);
        $action->setProcessedFilesForTest([
            ['url' => 'https://cdn.example.com/image.jpg', 'type' => MediaTypeEnum::IMAGE],
        ]);

        $result = $action->sendRespondIoMessageForTest('', '+15551234567');

        $this->assertCount(1, $result['messages']);
    }

    public function testSendRespondIoMessageMapsVideoFileToAttachment(): void
    {
        $client = Mockery::mock(RespondIOClient::class);
        $client->shouldReceive('sendAttachment')
            ->once()
            ->with('+15551234567', 'video', 'https://cdn.example.com/clip.mp4')
            ->andReturn(['id' => 'video']);
        $client->shouldReceive('sendMessage')
            ->once()
            ->with('+15551234567', 'caption')
            ->andReturn(['id' => 'body']);

        $action = $this->makeAction($this->makeLead(), $client);
        $action->setProcessedFilesForTest([
            ['url' => 'https://cdn.example.com/clip.mp4', 'type' => MediaTypeEnum::VIDEO],
        ]);

        $result = $action->sendRespondIoMessageForTest('caption', '+15551234567');

        $this->assertCount(2, $result['messages']);
    }

    public function testLookupPhoneNumberReturnsCanonicalValidNumber(): void
    {
        $httpClient = Mockery::mock(TwilioHttpClient::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, json_encode([
                'phone_number' => '+18095551234',
                'valid' => true,
                'validation_errors' => [],
                'line_type_intelligence' => [
                    'type' => 'mobile',
                ],
                'line_status' => [
                    'status' => 'reachable',
                ],
            ], JSON_THROW_ON_ERROR)));

        $client = new TwilioClient(
            username: 'ACtest',
            password: 'token',
            httpClient: $httpClient,
        );

        $action = $this->makeAction($this->makeLead());

        $this->assertSame(
            '+18095551234',
            $action->lookupPhoneNumberForTest($client, '8095551234')
        );
    }

    public function testLookupPhoneNumberRejectsInvalidNumber(): void
    {
        $httpClient = Mockery::mock(TwilioHttpClient::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, json_encode([
                'phone_number' => '+18095551234',
                'valid' => false,
                'validation_errors' => ['INVALID_LENGTH'],
            ], JSON_THROW_ON_ERROR)));

        $client = new TwilioClient(
            username: 'ACtest',
            password: 'token',
            httpClient: $httpClient,
        );

        $action = $this->makeAction($this->makeLead());

        $this->expectException(LeadMissingContactException::class);
        $this->expectExceptionMessage('Lead cellphone number 8095551234 is not valid');

        $action->lookupPhoneNumberForTest($client, '8095551234');
    }

    public function testLookupPhoneNumberRejectsUnreachableNumberToPreventError30003(): void
    {
        $httpClient = Mockery::mock(TwilioHttpClient::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, json_encode([
                'phone_number' => '+18095551234',
                'valid' => true,
                'validation_errors' => [],
                'line_type_intelligence' => [
                    'type' => 'mobile',
                ],
                'line_status' => [
                    'status' => 'unreachable',
                ],
            ], JSON_THROW_ON_ERROR)));

        $client = new TwilioClient(
            username: 'ACtest',
            password: 'token',
            httpClient: $httpClient,
        );

        $action = $this->makeAction($this->makeLead());

        $this->expectException(LeadMissingContactException::class);
        $this->expectExceptionMessage(
            'Lead cellphone number 8095551234 is unreachable and cannot receive SMS messages'
        );

        $action->lookupPhoneNumberForTest($client, '8095551234');
    }

    public function testLookupPhoneNumberRejectsLandlineToPreventError30006(): void
    {
        $httpClient = Mockery::mock(TwilioHttpClient::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, json_encode([
                'phone_number' => '+18095551234',
                'valid' => true,
                'validation_errors' => [],
                'line_type_intelligence' => [
                    'type' => 'landline',
                ],
            ], JSON_THROW_ON_ERROR)));

        $client = new TwilioClient(
            username: 'ACtest',
            password: 'token',
            httpClient: $httpClient,
        );

        $action = $this->makeAction($this->makeLead());

        $this->expectException(LeadMissingContactException::class);
        $this->expectExceptionMessage(
            'Lead cellphone number 8095551234 is a landline and cannot receive SMS messages'
        );

        $action->lookupPhoneNumberForTest($client, '8095551234');
    }

    public function testLookupPhoneNumberRejectsPremiumLineType(): void
    {
        $httpClient = Mockery::mock(TwilioHttpClient::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, json_encode([
                'phone_number' => '+18095551234',
                'valid' => true,
                'validation_errors' => [],
                'line_type_intelligence' => [
                    'type' => 'premium',
                ],
            ], JSON_THROW_ON_ERROR)));

        $client = new TwilioClient(
            username: 'ACtest',
            password: 'token',
            httpClient: $httpClient,
        );

        $action = $this->makeAction($this->makeLead());

        $this->expectException(LeadMissingContactException::class);
        $this->expectExceptionMessage(
            'Lead cellphone number 8095551234 is a premium line and cannot receive SMS messages'
        );

        $action->lookupPhoneNumberForTest($client, '8095551234');
    }

    public function testFailedResultClassifiesNonRetryableTwilioError(): void
    {
        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new \Twilio\Exceptions\RestException('Invalid destination', 21211, 400);
            }
        };

        $result = $action->execute('sms', 'Hello', '+18095550000');

        $this->assertFalse($result['success']);
        $this->assertSame(21211, $result['twilio_error_code']);
        $this->assertSame('validation_failed', $result['classification']);
        $this->assertFalse($result['retryable']);
    }

    public function testExecuteReportsAndReturnsErrorInsteadOfThrowing(): void
    {
        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new LeadMissingContactException('Lead does not have a cellphone number');
            }
        };

        $result = $action->execute('sms', 'Hello', '+18095550000');

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
        $this->assertSame('Lead does not have a cellphone number', $result['error']);
        $this->assertSame([], $result['messages']);
    }

    public function testOptedOutDestinationReturnsGracefullyWithoutReportingToSentry(): void
    {
        Exceptions::fake();

        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new LeadOptedOutException('Destination phone has opted out of SMS communications');
            }
        };

        $result = $action->execute('sms', 'Hello', '+18095550000');

        $this->assertFalse($result['success']);
        $this->assertSame('opted_out', $result['classification']);
        $this->assertFalse($result['retryable']);
        $this->assertSame([], $result['messages']);
        Exceptions::assertNothingReported();
    }

    public function testMissingContactDestinationIsNotReportedToSentry(): void
    {
        Exceptions::fake();

        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new LeadMissingContactException('Lead does not have a cellphone number');
            }
        };

        $action->execute('sms', 'Hello', '+18095550000');

        Exceptions::assertNothingReported();
    }

    public function testGenuineSendFailureStillReportsToSentry(): void
    {
        Exceptions::fake();

        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new RestException('Twilio account misconfigured', 21603, 400);
            }
        };

        $result = $action->execute('sms', 'Hello', '+18095550000');

        $this->assertSame('configuration_error', $result['classification']);
        Exceptions::assertReported(RestException::class);
    }

    public function testExecuteRethrowsRetryableTwilioFailure(): void
    {
        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new \Twilio\Exceptions\RestException('Temporary failure', 30003, 500);
            }
        };

        $this->expectExceptionCode(30003);

        $action->execute('sms', 'Hello', '+18095550000');
    }

    public function testTwilio21610ReturnsGracefullyAndOptsOutOnlyTheDestinationPhone(): void
    {
        $people = Mockery::mock();
        $people->shouldReceive('setPhoneOptOut')
            ->once()
            ->with('+18095551234')
            ->andReturn(1);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn($people);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn(null);
        $lead->shouldReceive('getAttribute')->with('uuid')->andReturn('lead-uuid');
        $lead->shouldReceive('getId')->andReturn(42);

        $action = $this->makeAction($lead);

        $result = $action->handleUnsubscribedRecipientForTest(
            '+18095551234',
            'Hello',
        );

        $this->assertTrue($result['opted_out']);
        $this->assertSame('sms', $result['channel']);
        $this->assertSame([], $result['messages']);
        $this->assertSame('opted_out', $result['classification']);
        $this->assertSame(21610, $result['twilio_error_code']);
        $this->assertFalse($result['retryable']);
        $this->assertSame('+18095551234', $result['to']);
        $this->assertTrue($action->optOutNoteRecorded);
    }

    public function testTwilioMessageIncludesConfiguredStatusCallback(): void
    {
        $sentData = [];
        $httpClient = Mockery::mock(TwilioHttpClient::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturnUsing(function (...$arguments) use (&$sentData): Response {
                $sentData = $arguments[3];

                return new Response(201, json_encode([
                    'sid' => 'SM123',
                    'status' => 'queued',
                ], JSON_THROW_ON_ERROR));
            });

        $client = new TwilioClient(
            username: 'ACtest',
            password: 'token',
            httpClient: $httpClient,
        );

        $action = $this->makeAction(
            $this->makeLead(),
            statusCallbackUrl: 'https://api.example.com/v1/receiver/status-callback',
        );

        $action->createTwilioMessageForTest(
            $client,
            '+18095551234',
            [
                'from' => '+18095550000',
                'body' => 'Hello',
            ]
        );

        $this->assertSame(
            'https://api.example.com/v1/receiver/status-callback',
            $sentData['StatusCallback']
        );
    }

    private function makeLead(): Lead
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')->with('allow_session_hijack', false)->andReturn(false);
        $company->shouldReceive('get')
            ->with(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn(null);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);
        $lead->shouldReceive('getAttribute')->with('uuid')->andReturn('lead-uuid');
        $lead->shouldReceive('getId')->andReturn(42);

        return $lead;
    }

    private function makeAction(
        Lead $lead,
        ?RespondIOClient $client = null,
        ?string $statusCallbackUrl = null,
    ): SendMessageToLeadAction {
        return new class ($lead, $client, $statusCallbackUrl) extends SendMessageToLeadAction {
            public bool $optOutNoteRecorded = false;

            public function __construct(
                Lead $lead,
                private readonly ?RespondIOClient $injectedClient = null,
                private readonly ?string $statusCallbackUrl = null,
            ) {
                parent::__construct($lead);
            }

            protected function getRespondIoClient(): RespondIOClient
            {
                return $this->injectedClient ?? parent::getRespondIoClient();
            }

            public function isRespondIoEnabledForTest(): bool
            {
                return $this->isRespondIoEnabled();
            }

            public function sendRespondIoMessageForTest(string $message, ?string $to = null): array
            {
                return $this->sendRespondIoMessage($message, $to);
            }

            public function lookupPhoneNumberForTest(TwilioClient $client, string $cellphone): string
            {
                return $this->lookupPhoneNumber($client, $cellphone);
            }

            public function createTwilioMessageForTest(TwilioClient $client, string $cellphone, array $payload): object
            {
                return $this->createTwilioMessage($client, $cellphone, $payload);
            }

            public function handleUnsubscribedRecipientForTest(string $cellphone, string $body): array
            {
                return $this->handleUnsubscribedRecipient($cellphone, $body);
            }

            protected function recordOptOutNote(string $cellphone, string $body): void
            {
                $this->optOutNoteRecorded = true;
            }

            protected function getTwilioStatusCallbackUrl(): ?string
            {
                return $this->statusCallbackUrl;
            }

            public function setProcessedFilesForTest(array $processedFiles): void
            {
                $this->processedFiles = $processedFiles;
            }

            public function setVideoEngagementsForTest(array $videoEngagements): void
            {
                $this->videoEngagements = $videoEngagements;
            }
        };
    }
}
