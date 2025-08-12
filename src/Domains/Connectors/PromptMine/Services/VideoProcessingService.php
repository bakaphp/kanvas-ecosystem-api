<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Services;

use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Actions\CreateNuggetMessageAction;
use Kanvas\Connectors\PromptMine\Notifications\VideoProcessingPushNotification;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Actions\DistributeMessagesToUsersAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Throwable;

class VideoProcessingService
{
    private const THUMBNAIL_FRAME_SECONDS = 2;

    public function __construct(
        protected Message $entity,
        protected Apps $app
    ) {
    }

    public function checkVideoProcessingStatus(
        string $requestId,
        string $videoModel,
        array $params = []
    ): void {
        $key = IntegrationsEnum::PROMPT_MINE->value . '_video_processed_' . $requestId;

        // Check if this video has already been processed
        if ($this->entity->get($key)) {
            return;
        }

        try {
            // Refresh entity to get latest data
            $this->entity->refresh();

            // Check if processing was completed by another process
            if (
                isset($this->entity->message['video_processing_status']) &&
                $this->entity->message['video_processing_status'] === 'COMPLETED'
            ) {
                return;
            }

            // Poll for the result with retries
            $result = $this->pollForVideoResult($requestId, $videoModel);

            if ($result['status'] === 'COMPLETED' && isset($result['video_url'])) {
                // Mark as processed to prevent duplicate processing
                $this->entity->set($key, true);

                // Process the completed video
                $this->processCompletedVideo($result['video_url'], $requestId, $params);
            } elseif ($result['status'] === 'FAILED') {
                // Update status to failed
                $this->updateVideoProcessingStatus('FAILED', $result['error'] ?? 'Video processing failed');
            } else {
                // If still processing, schedule another check in 2 minutes
                $this->scheduleVideoProcessingRetry($requestId, $videoModel, $params);
            }
        } catch (Exception $e) {
            report($e);
            $this->updateVideoProcessingStatus('FAILED', $e->getMessage());
        }
    }

    public function retryVideoProcessingCheck(
        string $requestId,
        string $videoModel,
        array $params = []
    ): void {
        try {
            // Check again by calling the polling logic
            $result = $this->pollForVideoResult($requestId, $videoModel);

            if ($result['status'] === 'COMPLETED' && isset($result['video_url'])) {
                $key = IntegrationsEnum::PROMPT_MINE->value . '_video_processed_' . $requestId;
                if (! $this->entity->get($key)) {
                    $this->entity->set($key, true);
                    $this->processCompletedVideo($result['video_url'], $requestId, $params);
                }
            } elseif ($result['status'] === 'FAILED') {
                $this->updateVideoProcessingStatus('FAILED', $result['error'] ?? 'Video processing failed');
            }
        } catch (Exception $e) {
            report($e);
            $this->updateVideoProcessingStatus('FAILED', $e->getMessage());
        }
    }

    protected function scheduleVideoProcessingRetry(
        string $requestId,
        string $videoModel,
        array $params
    ): void {
        $entity = $this->entity;
        $app = $this->app;

        dispatch(function () use ($entity, $app, $requestId, $videoModel, $params) {
            $service = new VideoProcessingService($entity, $app);
            $service->retryVideoProcessingCheck($requestId, $videoModel, $params);
        })->delay(now()->addMinutes(2));
    }

    public function updateVideoProcessingStatus(string $status, ?string $error = null): void
    {
        $messageCopy = $this->entity->message;
        $messageCopy['video_processing_status'] = $status;
        if ($error) {
            $messageCopy['video_error'] = $error;
        }
        $this->entity->message = $messageCopy;
        $this->entity->save();
    }

    protected function pollForVideoResult(string $requestId, string $videoModel): array
    {
        $maxAttempts = 3;
        $attempt = 0;

        // Reconstruct API URL for polling
        $isImageToVideo = isset($this->entity->message['hasFiles']) && $this->entity->message['hasFiles'] === true;
        $baseApiUrl = $this->app->get('PROMPT_VIDEO_API_URL');
        $videoKey = $isImageToVideo ? 'fal-ai/image-to-video' : 'fal-ai/text-to-video';
        $apiUrl = $baseApiUrl . '/api/v2/video/' . $videoKey;

        while ($attempt < $maxAttempts) {
            try {
                // Check status
                $statusResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($apiUrl, [
                    'operation' => 'status',
                    'requestId' => $requestId,
                    'model' => $videoModel,
                    'logs' => true,
                ]);

                $statusData = $statusResponse->json();

                if ($statusData['status'] === 'COMPLETED') {
                    // Get the result
                    $resultResponse = Http::withHeaders([
                        'Content-Type' => 'application/json',
                    ])->post($apiUrl, [
                        'operation' => 'result',
                        'requestId' => $requestId,
                        'model' => $videoModel,
                    ]);

                    $resultData = $resultResponse->json();
                    $videoUrl = $this->extractVideoUrl($resultData);

                    return [
                        'status' => 'COMPLETED',
                        'video_url' => $videoUrl,
                        'result_data' => $resultData,
                    ];
                } elseif ($statusData['status'] === 'FAILED') {
                    return [
                        'status' => 'FAILED',
                        'error' => 'Video processing failed on external service',
                        'details' => $statusData,
                    ];
                } else {
                    // Still processing
                    return [
                        'status' => 'IN_PROGRESS',
                        'details' => $statusData,
                    ];
                }
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    return [
                        'status' => 'FAILED',
                        'error' => 'Failed to check video status after ' . $maxAttempts . ' attempts: ' . $e->getMessage(),
                    ];
                }
                sleep(2); // Wait before retry
            }
        }

        return [
            'status' => 'FAILED',
            'error' => 'Maximum polling attempts reached',
        ];
    }

    protected function processCompletedVideo(string $videoUrl, string $requestId, array $params): void
    {
        try {
            // Download and upload video
            $fileSystemRecord = $this->downloadAndUploadVideo($videoUrl);

            // Finalize processing
            $this->finalizeProcessing($fileSystemRecord, $videoUrl, $params, $requestId);
        } catch (Exception $e) {
            report($e);
            $this->updateVideoProcessingStatus('FAILED', $e->getMessage());
        }
    }

    protected function downloadAndUploadVideo(string $videoUrl): Filesystem
    {
        // Download the video file
        $videoContent = file_get_contents($videoUrl);
        $filename = 'video_' . uniqid() . '.mp4';

        if ($videoContent === false) {
            throw new Exception("Failed to download video from URL: {$videoUrl}");
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'video_');
        file_put_contents($tempFile, $videoContent);

        // Get the file's mime type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempFile);

        $uploadedFile = new UploadedFile(
            $tempFile,
            $filename,
            $mimeType,
            null,
            true
        );

        $filesystem = new FilesystemServices($this->entity->app);
        $fileSystemRecord = $filesystem->upload($uploadedFile, $this->entity->user);

        // Clean up temporary file
        @unlink($tempFile);

        return $fileSystemRecord;
    }

    protected function finalizeProcessing(
        Filesystem $fileSystemRecord,
        ?string $processedVideoUrl = null,
        array $params = [],
        ?string $requestId = null
    ): array {
        // Generate a new title using AI if no title is set
        try {
            if (empty($this->entity->message['title']) && $this->entity->message['prompt']) {
                $title = $this->generateTitleByPrompt($this->entity->message['prompt']);
            } else {
                $title = $this->entity->message['title'];
            }
        } catch (Throwable $e) {
            report($e);
            $title = $this->entity->message['prompt'];
        }

        $totalDelivery = 0;
        $thumbnailImageUrl = $this->entity->getFiles()->first() ?? $this->generateThumbnailFromVideo($fileSystemRecord->url);
        $cdnThumbnailUrl = $this->entity->app->get('cloud-cdn') . '/' . $thumbnailImageUrl->path;
        // Create a new nugget message with the processed video
        $cdnVideoUrl = $this->entity->app->get('cloud-cdn') . '/' . $fileSystemRecord->path;
        $createNuggetMessage = (new CreateNuggetMessageAction(
            parentMessage: $this->entity,
            messageData: [
                'title' => trim($title),
                'type' => 'video-format',
                'video' => $cdnVideoUrl,
                'thumbnail' => $cdnThumbnailUrl,
                'is_posted' => true,
            ],
        ))->execute();

        $messageCopy = $this->entity->message;
        $messageCopy['ai_video'] = $cdnVideoUrl;
        $messageCopy['video_processing_status'] = 'COMPLETED';
        $this->entity->message = $messageCopy;
        $this->entity->is_public = 1;
        $this->entity->save();

        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $params['via'] ?? ['database']
        );

        $title = trim($title);

        try {
            // Send notification to the user
            $newMessageNotification = new VideoProcessingPushNotification(
                user: $this->entity->user,
                entity: $this->entity,
                message: 'Tap to view your AI-generated video on prompt mine.',
                title: 'Video is ready ' . $title,
                via: $endViaList,
                templates: [
                    'email_template' => $params['email_template'] ?? null,
                    'push_template' => $params['push_template'] ?? null,
                ],
            );
            $this->entity->user->notify($newMessageNotification);

            $totalDelivery = new DistributeMessagesToUsersAction($this->entity, $this->entity->app)->execute();
        } catch (InternalServerErrorException $e) {
            report($e);
        }

        // Turn type to prompt
        $this->entity->message_types_id = MessageType::fromApp($this->entity->app)->where('verb', 'prompt')->firstOrFail()->getId();
        $this->entity->update();

        return [
            'message' => 'Video processed successfully',
            'total_delivery' => $totalDelivery,
            'result' => true,
            'user_id' => $this->entity->user->getId(),
            'message_data' => $this->entity->message,
            'message_id' => $this->entity->getId(),
            'nugget_message_id' => $createNuggetMessage->getId(),
            'processed_video_url' => $processedVideoUrl,
            'request_id' => $requestId,
        ];
    }

    private function extractVideoUrl(array $resultResponse): ?string
    {
        // Check for data.video.url format
        if (isset($resultResponse['data']['video']['url'])) {
            return $resultResponse['data']['video']['url'];
        }

        // Check for data.videos[0].url format (if multiple videos)
        if (
            isset($resultResponse['data']['videos']) &&
            is_array($resultResponse['data']['videos']) &&
            ! empty($resultResponse['data']['videos']) &&
            isset($resultResponse['data']['videos'][0]['url'])
        ) {
            return $resultResponse['data']['videos'][0]['url'];
        }

        return null;
    }

    private function generateTitleByPrompt(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt('Generate a short concise title from this prompt: ' . $prompt . '. Choose just one title, dont give me suggestions')
            ->generate();

        return str_replace(['```', 'json'], '', $response->text);
    }

    private function generateThumbnailFromVideo(string $videoUrl): ?Filesystem
    {
        // Download the video file
        $videoContent = file_get_contents($videoUrl);

        if ($videoContent === false) {
            throw new Exception("Failed to download video from URL: {$videoUrl}");
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'video_');
        file_put_contents($tempFile, $videoContent);

        //Create thumbnail from video using FFMpeg
        try {
            $ffmpeg = FFMpeg::create();
            $video = $ffmpeg->open($tempFile);
            $frame = $video->frame(TimeCode::fromSeconds(self::THUMBNAIL_FRAME_SECONDS));
            $thumbnailFileName = 'thumbnail_' . uniqid() . '.png';
            $thumbnailTempFile = tempnam(sys_get_temp_dir(), 'thumbnail_') . '.png';
            $frame->save($thumbnailTempFile);

            // Get the file's mime type
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $thumbnailMimeType = $finfo->file($thumbnailTempFile);

            // Create an UploadedFile instance for the thumbnail
            $uploadedThumbnail = new UploadedFile(
                $thumbnailTempFile,
                $thumbnailFileName,
                $thumbnailMimeType,
                null,
                true
            );

            $filesystem = new FilesystemServices($this->entity->app);
            $fileSystemRecord = $filesystem->upload($uploadedThumbnail, $this->entity->user);

            // Clean up temporary file
            @unlink($tempFile);
            @unlink($thumbnailTempFile);

            return $fileSystemRecord;
        } catch (Exception $e) {
            // Handle the exception
            report($e->getMessage());

            return null;
        }
    }
}
