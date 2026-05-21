<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

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
}
