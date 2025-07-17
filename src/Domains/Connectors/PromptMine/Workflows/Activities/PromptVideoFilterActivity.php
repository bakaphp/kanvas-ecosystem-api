<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\CreateNuggetMessageAction;
use Kanvas\Connectors\PromptMine\Actions\ProcessVideoRequestAction;
use Kanvas\Connectors\PromptMine\Notifications\VideoProcessingPushNotification;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Actions\DistributeMessagesToUsersAction;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Throwable;

class PromptVideoFilterActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?string $apiUrl = null;
    protected ?Apps $app = null;
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        sleep($app->get('PROMPT_VIDEO_WAIT_TIME') ?? 5);
        $this->app = $app;

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params) {
                $entity->setPrivate();

                try {
                    // Use the ProcessVideoRequestAction for the core logic
                    $processVideoAction = new ProcessVideoRequestAction($entity, $app, $params);
                    $result = $processVideoAction->execute();

                    if ($result['result'] && isset($result['request_id'])) {
                        // Schedule delayed processing
                        $this->scheduleVideoProcessingCheck(
                            $entity,
                            $app,
                            $result['request_id'],
                            $result['model'],
                            $params
                        );
                    }

                    return $result;
                } catch (Exception $e) {
                    report($e);

                    return [
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Error submitting video processing request: ' . $e->getMessage(),
                    ];
                }
            },
            company: $company,
        );
    }

    /**
     * Schedule a delayed job to check video processing status
     */
    protected function scheduleVideoProcessingCheck(Model $entity, AppInterface $app, string $requestId, string $videoModel, array $params): void
    {
        dispatch(function () use ($entity, $app, $requestId, $videoModel, $params) {
            $key = IntegrationsEnum::PROMPT_MINE->value . '_video_processed_' . $requestId;

            // Check if this video has already been processed
            if ($entity->get($key)) {
                return;
            }

            try {
                // Refresh entity to get latest data
                $entity->refresh();

                // Check if processing was completed by another process
                if (isset($entity->message['video_processing_status']) &&
                    $entity->message['video_processing_status'] === 'COMPLETED') {
                    return;
                }

                // Poll for the result with retries
                $result = $this->pollForVideoResult($requestId, $videoModel, $entity->app);

                if ($result['status'] === 'COMPLETED' && isset($result['video_url'])) {
                    // Mark as processed to prevent duplicate processing
                    $entity->set($key, true);

                    // Process the completed video
                    $this->processCompletedVideo($entity, $result['video_url'], $requestId, $params);
                } elseif ($result['status'] === 'FAILED') {
                    // Update status to failed
                    $this->updateVideoProcessingStatus($entity, 'FAILED', $result['error'] ?? 'Video processing failed');
                } else {
                    // If still processing, schedule another check in 2 minutes
                    $this->scheduleVideoProcessingRetry($entity, $app, $requestId, $videoModel, $params);
                }
            } catch (Exception $e) {
                report($e);
                $this->updateVideoProcessingStatus($entity, 'FAILED', $e->getMessage());
            }
        })->delay(now()->addMinutes(8)); // Wait 8 minutes before first check
    }

    /**
     * Schedule a retry check for video processing
     */
    protected function scheduleVideoProcessingRetry(Model $entity, AppInterface $app, string $requestId, string $videoModel, array $params): void
    {
        dispatch(function () use ($entity, $app, $requestId, $videoModel, $params) {
            try {
                // Check again by calling the polling logic
                $result = $this->pollForVideoResult($requestId, $videoModel, $entity->app);

                if ($result['status'] === 'COMPLETED' && isset($result['video_url'])) {
                    $key = IntegrationsEnum::PROMPT_MINE->value . '_video_processed_' . $requestId;
                    if (! $entity->get($key)) {
                        $entity->set($key, true);
                        $this->processCompletedVideo($entity, $result['video_url'], $requestId, $params);
                    }
                } elseif ($result['status'] === 'FAILED') {
                    $this->updateVideoProcessingStatus($entity, 'FAILED', $result['error'] ?? 'Video processing failed');
                }
            } catch (Exception $e) {
                report($e);
                $this->updateVideoProcessingStatus($entity, 'FAILED', $e->getMessage());
            }
        })->delay(now()->addMinutes(2));
    }

    /**
     * Update video processing status in message
     */
    protected function updateVideoProcessingStatus(Model $entity, string $status, ?string $error = null): void
    {
        $messageCopy = $entity->message;
        $messageCopy['video_processing_status'] = $status;
        if ($error) {
            $messageCopy['video_error'] = $error;
        }
        $entity->message = $messageCopy;
        $entity->save();
    }

    /**
     * Get the company for this workflow
     */
    protected function getCompany(AppInterface $app, Model $entity): Companies
    {
        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);

            return $branch->company;
        } catch (ModelNotFoundException $e) {
            return $entity->company;
        }
    }

    /**
     * Poll for video processing result with retries
     */
    protected function pollForVideoResult(string $requestId, string $videoModel, Apps $app): array
    {
        $maxAttempts = 3;
        $attempt = 0;

        // Reconstruct API URL for polling
        $isImageToVideo = isset($this->entity->message['hasFiles']) && $this->entity->message['hasFiles'] === true;
        $baseApiUrl = $app->get('PROMPT_VIDEO_API_URL');
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

    /**
     * Process completed video - download, upload, create nugget, send notifications
     */
    protected function processCompletedVideo(Model $entity, string $videoUrl, string $requestId, array $params): void
    {
        try {
            // Download and upload video
            $fileSystemRecord = $this->downloadAndUploadVideo($videoUrl, $entity);

            // Finalize processing
            $this->finalizeProcessing($entity, $fileSystemRecord, $videoUrl, $params, $requestId);
        } catch (Exception $e) {
            report($e);
            $this->updateVideoProcessingStatus($entity, 'FAILED', $e->getMessage());
        }
    }

    /**
     * Download video from URL and upload to our filesystem
     */
    protected function downloadAndUploadVideo(string $videoUrl, Model $entity): Filesystem
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

        $filesystem = new FilesystemServices($entity->app);
        $fileSystemRecord = $filesystem->upload($uploadedFile, $entity->user);

        // Clean up temporary file
        @unlink($tempFile);

        return $fileSystemRecord;
    }

    /**
     * Finalize the processing by creating a nugget message and sending notification
     */
    protected function finalizeProcessing(
        Model $entity,
        Filesystem $fileSystemRecord,
        ?string $processedVideoUrl = null,
        array $params = [],
        ?string $requestId = null
    ): array {
        // Generate a new title using AI if no title is set
        try {
            if (empty($entity->message['title']) && $entity->message['prompt']) {
                $title = $this->generateTitleByPrompt($entity->message['prompt']);
            } else {
                $title = $entity->message['title'];
            }
        } catch (Throwable $e) {
            report($e);
            $title = $entity->message['prompt'];
        }

        $totalDelivery = 0;
        // Create a new nugget message with the processed video
        $cdnVideoUrl = $entity->app->get('cloud-cdn') . '/' . $fileSystemRecord->path;
        $createNuggetMessage = (new CreateNuggetMessageAction(
            parentMessage: $entity->parent_id ? $entity->parent : $entity,
            messageData: [
                'title' => trim($title),
                'type' => 'video-format',
                'video' => $cdnVideoUrl,
            ],
        ))->execute();

        $messageCopy = $entity->message;
        $messageCopy['ai_video'] = $cdnVideoUrl;
        $messageCopy['video_processing_status'] = 'COMPLETED';
        $entity->message = $messageCopy;
        $entity->is_public = 1;
        $entity->save();

        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $params['via'] ?? ['database']
        );

        $title = trim($title);

        try {
            // Send notification to the user
            $newMessageNotification = new VideoProcessingPushNotification(
                user: $entity->user,
                entity: $entity,
                message: "Your video for {$title} has been processed",
                title: 'Video Processed',
                via: $endViaList,
                templates: [
                    'email_template' => $params['email_template'],
                    'push_template' => $params['push_template'],
                ],
            );
            $entity->user->notify($newMessageNotification);

            $totalDelivery = new DistributeMessagesToUsersAction($entity, $entity->app)->execute();
        } catch (InternalServerErrorException $e) {
            report($e);
        }

        // Turn type to prompt
        $entity->message_types_id = MessageType::fromApp($entity->app)->where('verb', 'prompt')->firstOrFail()->getId();
        $entity->update();

        return [
            'message' => 'Video processed successfully',
            'total_delivery' => $totalDelivery,
            'result' => true,
            'user_id' => $entity->user->getId(),
            'message_data' => $entity->message,
            'message_id' => $entity->getId(),
            'nugget_message_id' => $createNuggetMessage->getId(),
            'processed_video_url' => $processedVideoUrl,
            'request_id' => $requestId,
        ];
    }

    /**
     * Extract video URL from the result response
     */
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
}
