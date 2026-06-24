<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

use InvalidArgumentException;
use Kanvas\Connectors\RespondIO\Client as RespondIOClient;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum as RespondIOConfigurationEnum;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Mockery;
use Tests\TestCaseUnit;

final class SendMessageToLeadActionTest extends TestCaseUnit
{
    public function testTwilioMediaUrlsIncludeDocuments(): void
    {
        $app = Mockery::mock();
        $app->shouldReceive('get')
            ->with(TwilioConfigurationEnum::TWILIO_MMS_MAX_TOTAL_MEDIA->value)
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class);
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

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn($people);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $client = Mockery::mock(RespondIOClient::class);
        $action = $this->makeAction($lead, $client);

        $this->expectException(InvalidArgumentException::class);
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

    private function makeLead(): Lead
    {
        $company = Mockery::mock();
        $company->shouldReceive('get')->with('allow_session_hijack', false)->andReturn(false);

        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);
        $lead->shouldReceive('getAttribute')->with('uuid')->andReturn('lead-uuid');
        $lead->shouldReceive('getId')->andReturn(42);

        return $lead;
    }

    private function makeAction(Lead $lead, ?RespondIOClient $client = null): SendMessageToLeadAction
    {
        return new class ($lead, $client) extends SendMessageToLeadAction {
            public function __construct(Lead $lead, private readonly ?RespondIOClient $injectedClient = null)
            {
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
