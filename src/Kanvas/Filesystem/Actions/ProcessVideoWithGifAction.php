<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Services\VideoToGifService;

class ProcessVideoWithGifAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly UserInterface $user,
        protected readonly string $videoUrl,
    ) {
    }

    public function execute(): ?array
    {
        $isEnabled = (bool) $this->company->get(CompanyConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value);

        if (! $isEnabled) {
            return null;
        }

        try {
            $videoToGifService = new VideoToGifService(
                $this->app,
                $this->company,
                $this->user
            );

            $result = $videoToGifService->processVideoUrl($this->videoUrl);

            return [
                'gif' => [
                    'url' => $result['gif']->url,
                    'name' => $result['gif']->name,
                    'type' => MediaTypeEnum::IMAGE,
                    'file_type' => 'gif',
                    'filesystem' => $result['gif'],
                ],
                'video' => [
                    'url' => $result['video']->url,
                    'name' => $result['video']->name,
                    'type' => MediaTypeEnum::VIDEO,
                    'file_type' => pathinfo($result['video']->name, PATHINFO_EXTENSION) ?: 'mp4',
                    'filesystem' => $result['video'],
                ],
            ];
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }
}
