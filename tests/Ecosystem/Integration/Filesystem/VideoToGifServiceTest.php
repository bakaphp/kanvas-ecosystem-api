<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Actions\ProcessVideoWithGifAction;
use Kanvas\Filesystem\Services\VideoToGifService;
use Tests\TestCase;

class VideoToGifServiceTest extends TestCase
{
    // Settings land in Redis, which no test transaction rolls back.
    protected function tearDown(): void
    {
        auth()->user()->getCurrentCompany()->del(ConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value);

        parent::tearDown();
    }

    public function testGifGenerationIsOnWhenTheCompanyHasNoSetting(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->del(ConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value);

        $this->assertTrue($this->service()->isEnabled());
    }

    public function testCompanyCanTurnGifGenerationOff(): void
    {
        $company = auth()->user()->getCurrentCompany();

        foreach ([false, 0, '0', 'false'] as $off) {
            $company->set(ConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value, $off);

            $this->assertFalse($this->service()->isEnabled(), 'Expected off for ' . var_export($off, true));
        }

        $company->set(ConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value, true);

        $this->assertTrue($this->service()->isEnabled());
    }

    public function testTurnedOffCompanySkipsVideoProcessing(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(ConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value, false);

        $result = new ProcessVideoWithGifAction(
            app(Apps::class),
            $company,
            $user,
            'https://example.com/clip.mp4'
        )->execute();

        $this->assertNull($result);
    }

    private function service(): VideoToGifService
    {
        $user = auth()->user();

        return new VideoToGifService(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
