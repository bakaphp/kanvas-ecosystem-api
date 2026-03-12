<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;

class VideoToGifService
{
    protected const int DEFAULT_GIF_DURATION = 5;
    protected const int GIF_WIDTH = 480;
    protected const int GIF_FPS = 10;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user
    ) {
    }

    /**
     * Process a video URL and generate both uploaded video and GIF files.
     *
     * @return array{video: Filesystem, gif: Filesystem}
     */
    public function processVideoUrl(string $videoUrl): array
    {
        $tempVideoPath = $this->downloadVideo($videoUrl);

        try {
            $videoFilesystem = $this->uploadVideoFile($tempVideoPath, $videoUrl);
            $gifFilesystem = $this->generateAndUploadGif($tempVideoPath);

            return [
                'video' => $videoFilesystem,
                'gif' => $gifFilesystem,
            ];
        } finally {
            @unlink($tempVideoPath);
        }
    }

    /**
     * Download video from URL to temporary file.
     */
    protected function downloadVideo(string $videoUrl): string
    {
        $response = Http::timeout(60)
            ->retry(3, 100)
            ->get($videoUrl);

        if (! $response->successful()) {
            throw new Exception("Failed to download video from URL: {$videoUrl}");
        }

        $extension = $this->getExtensionFromUrl($videoUrl) ?: 'mp4';
        $tempFile = tempnam(sys_get_temp_dir(), 'video_') . '.' . $extension;
        file_put_contents($tempFile, $response->body());

        return $tempFile;
    }

    /**
     * Upload the video file to storage.
     */
    protected function uploadVideoFile(string $tempVideoPath, string $originalUrl): Filesystem
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempVideoPath);

        $extension = $this->getExtensionFromUrl($originalUrl) ?: 'mp4';
        $filename = 'video_' . uniqid() . '.' . $extension;

        $uploadedFile = new UploadedFile(
            $tempVideoPath,
            $filename,
            $mimeType,
            null,
            true
        );

        $filesystem = new FilesystemServices($this->app, $this->company);

        return $filesystem->upload($uploadedFile, $this->user);
    }

    /**
     * Generate GIF from video and upload to storage.
     */
    protected function generateAndUploadGif(string $videoPath): Filesystem
    {
        $gifDuration = $this->getGifDuration();

        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($videoPath);

        $gifFileName = 'gif_' . uniqid() . '.gif';
        $gifTempFile = tempnam(sys_get_temp_dir(), 'gif_') . '.gif';

        $video
            ->gif(TimeCode::fromSeconds(0), new \FFMpeg\Coordinate\Dimension(self::GIF_WIDTH, 0), $gifDuration)
            ->save($gifTempFile);

        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $gifMimeType = $finfo->file($gifTempFile);

            $uploadedGif = new UploadedFile(
                $gifTempFile,
                $gifFileName,
                $gifMimeType,
                null,
                true
            );

            $filesystem = new FilesystemServices($this->app, $this->company);

            return $filesystem->upload($uploadedGif, $this->user);
        } finally {
            @unlink($gifTempFile);
        }
    }

    /**
     * Get GIF duration from company configuration.
     */
    protected function getGifDuration(): int
    {
        $configuredDuration = $this->company->get(ConfigurationEnum::GIF_DURATION_SECONDS->value);

        return $configuredDuration !== null ? (int) $configuredDuration : self::DEFAULT_GIF_DURATION;
    }

    /**
     * Check if video GIF generation is enabled for the company.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->company->get(ConfigurationEnum::ENABLE_VIDEO_GIF_GENERATION->value);
    }

    /**
     * Get file extension from URL.
     */
    protected function getExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $pathInfo = pathinfo($path);

        return $pathInfo['extension'] ?? '';
    }
}
