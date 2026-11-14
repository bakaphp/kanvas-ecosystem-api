<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum as RespondIOConfigurationEnum;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Exceptions\LeadOptedOutException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Mockery;
use RuntimeException;
use Tests\TestCaseUnit;

final class SendMessageToLeadActionTest extends TestCaseUnit
{
    public function testInternalAssistantEmailOmitsPersonaSignature(): void
    {
        $this->assertEmailSignature(false, true, SalesManagerAgent::class);
    }

    public function testCustomerFacingAgentEmailKeepsPersonaSignature(): void
    {
        $this->assertEmailSignature(true, true, SalesAgent::class);
    }

    public function testExplicitlyDisabledSignatureStaysDisabled(): void
    {
        $this->assertEmailSignature(false, false, SalesAgent::class);
        $this->assertEmailSignature(false, false, null);
    }

    public function testLegacyEmailWithoutAgentKeepsSignatureDefault(): void
    {
        $this->assertEmailSignature(true, true, null);
    }

    private function assertEmailSignature(bool $expected, bool $signature, ?string $handler): void
    {
        $agent = $handler === null ? null : new Agent();
        $agent?->setRelation('type', (object) ['handler' => $handler]);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('get')->andReturn(null)->byDefault();
        $lead->shouldReceive('getAttribute')->with('people')->andReturn(null);

        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendEmailMessage(
                string $message,
                ?string $title = null,
                bool $signature = true,
                ?string $to = null,
                ?array $cc = null
            ): array {
                return compact('signature', 'message', 'title', 'to', 'cc') + ['fromAgent' => $this->speakingAgent];
            }
        };

        $result = $action->execute(
            channel: 'email',
            message: 'Here is the information you requested.',
            title: 'Your request',
            signature: $signature,
            to: 'prospect@example.com',
            cc: ['owner@example.com'],
            fromAgent: $agent,
        );

        $this->assertSame($expected, $result['signature']);
        $this->assertSame($agent, $result['fromAgent']);
        $this->assertSame('Here is the information you requested.', $result['message']);
        $this->assertSame('Your request', $result['title']);
        $this->assertSame('prospect@example.com', $result['to']);
        $this->assertSame(['owner@example.com'], $result['cc']);
    }

    public function testVideoGoesOutAsAPlainFileWhenItsEngagementCannotBeCreated(): void
    {
        Exceptions::fake();

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('app')->andThrow(new RuntimeException('no engagement'));

        $action = new class ($lead) extends SendMessageToLeadAction {
            public function createVideoEngagementsForTest(array $processedFiles): array
            {
                $this->processedFiles = $processedFiles;
                $this->createVideoEngagements();

                return $this->processedFiles;
            }
        };

        $video = [
            'url' => 'https://cdn.example.com/clip.mp4',
            'name' => 'clip.mp4',
            'type' => MediaTypeEnum::VIDEO,
            'file_type' => 'mp4',
            'filesystem' => Mockery::mock(Filesystem::class),
        ];

        $processedFiles = $action->createVideoEngagementsForTest([
            [
                'is_processed_video' => true,
                'video' => $video,
                'gif' => ['url' => 'https://cdn.example.com/clip.gif'],
            ],
        ]);

        $this->assertSame([$video], $processedFiles);
        $this->assertSame([], $action->getVideoEngagements());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function testTwilioMediaUrlsIncludeDocumentsButNeverAVideoOrItsGif(): void
    {
        $app = Mockery::mock();
        $app->shouldReceive('get')
            ->with(LeadConfigurationEnum::SMS_MEDIA_MAX_TOTAL->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('get')->andReturn(null)->byDefault();
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

            public function getMediaUrlsForSmsForTest(): array
            {
                return $this->getMediaUrlsForSms();
            }
        };

        $action->setVideoEngagementsForTest([
            ['url' => 'https://salink.app/abc123'],
        ]);

        $action->setProcessedFilesForTest([
            [
                'is_processed_video' => true,
                'video' => ['url' => 'https://cdn.example.com/clip.mp4'],
                'gif' => ['url' => 'https://cdn.example.com/video-preview.gif'],
            ],
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
            'https://cdn.example.com/image.jpg',
            'https://cdn.example.com/voice.mp3',
            'https://cdn.example.com/contract.pdf',
        ], $action->getMediaUrlsForSmsForTest());
    }

    public function testIsRespondIoEnabledReturnsFalseWhenSettingMissing(): void
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')
            ->with(RespondIOConfigurationEnum::ENABLED->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('get')->andReturn(null)->byDefault();
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
        $lead->shouldReceive('get')->andReturn(null)->byDefault();
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $action = $this->makeAction($lead);

        $this->assertTrue($action->isRespondIoEnabledForTest());
    }

    public function testFailedResultClassifiesWhatTheActionItselfRefuses(): void
    {
        $lead = $this->makeLead();
        $action = new class ($lead) extends SendMessageToLeadAction {
            protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
            {
                throw new LeadMissingContactException('Lead does not have a cellphone');
            }
        };

        $result = $action->execute('sms', 'Hello', '+18095550000');

        $this->assertFalse($result['success']);
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
                throw new InvalidArgumentException('Unsupported media for this channel');
            }
        };

        $result = $action->execute('sms', 'Hello', '+18095550000');

        $this->assertSame('validation_failed', $result['classification']);
        Exceptions::assertReported(InvalidArgumentException::class);
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

    private function makeLead(?People $people = null): Lead
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')->with('allow_session_hijack', false)->andReturn(false);
        $company->shouldReceive('get')
            ->with(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('get')->andReturn(null)->byDefault();
        $lead->shouldReceive('getAttribute')->with('people')->andReturn($people);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);
        $lead->shouldReceive('getAttribute')->with('uuid')->andReturn('lead-uuid');
        $lead->shouldReceive('getId')->andReturn(42);

        return $lead;
    }

    private function makeAction(
        Lead $lead,
        ?string $statusCallbackUrl = null,
    ): SendMessageToLeadAction {
        return new class ($lead, $statusCallbackUrl) extends SendMessageToLeadAction {
            public bool $optOutNoteRecorded = false;

            public function __construct(
                Lead $lead,
                private readonly ?string $statusCallbackUrl = null,
            ) {
                parent::__construct($lead);
            }

            public function isRespondIoEnabledForTest(): bool
            {
                return $this->isRespondIoEnabled();
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
