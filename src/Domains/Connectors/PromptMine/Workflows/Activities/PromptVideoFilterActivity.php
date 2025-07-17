<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\CreateNuggetMessageAction;
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

        // Extract video model dynamically from message - use the full value as is
        $videoModel = $entity->message['ai_model']['value'] ?? 'fal-ai/veo3';
        $videoType = $entity->message['type'] ?? 'video-format';

        // Determine if it's text-to-video or image-to-video based on hasFiles flag
        $isImageToVideo = isset($entity->message['hasFiles']) && $entity->message['hasFiles'] === true;

        // Construct the API URL based on video type
        $baseApiUrl = $entity->app->get('PROMPT_VIDEO_API_URL');
        $videoKey = $isImageToVideo ? 'fal-ai/image-to-video' : 'fal-ai/text-to-video';
        $this->apiUrl = $baseApiUrl . '/api/v2/video/' . $videoKey;

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params, $videoModel, $videoType, $isImageToVideo) {
                $entity->setPrivate();

                if (empty($this->apiUrl)) {
                    return [
                        'result' => false,
                        'message' => 'Video API URL not configured',
                    ];
                }

                try {
                    // Check if we already have a request_id (in case of retry)
                    $existingRequestId = $entity->message['video_request_id'] ?? null;

                    if ($existingRequestId) {
                        // If we already have a request ID, just return success
                        // The delayed job will handle the processing
                        return [
                            'result' => true,
                            'model' => $videoModel,
                            'request_id' => $existingRequestId,
                            'message' => 'Video processing request already submitted. Processing continues asynchronously.',
                            'message_id' => $entity->getId(),
                            'status' => 'IN_QUEUE',
                        ];
                    }

                    if ($isImageToVideo) {
                        // Process image-to-video
                        $messageFiles = $entity->getFiles();
                        if ($messageFiles->isEmpty()) {
                            return [
                                'result' => false,
                                'message' => 'Message does not have any files for image-to-video processing',
                            ];
                        }

                        $imageUrl = $messageFiles->first()->url;
                        $requestId = $this->submitImageToVideo($imageUrl, $videoModel, $entity, $params);
                    } else {
                        // Process text-to-video
                        $requestId = $this->submitTextToVideo($videoModel, $entity, $params);
                    }

                    if ($requestId === null) {
                        return [
                            'result' => false,
                            'model' => $videoModel,
                            'message' => 'Failed to submit video processing request',
                        ];
                    }

                    // Store the request ID for tracking
                    $messageCopy = $entity->message;
                    $messageCopy['video_request_id'] = $requestId;
                    $messageCopy['video_processing_status'] = 'IN_QUEUE';
                    $entity->message = $messageCopy;
                    $entity->save();

                    // Schedule delayed processing
                    $this->scheduleVideoProcessingCheck($entity, $app, $requestId, $videoModel, $params);

                    return [
                        'result' => true,
                        'model' => $videoModel,
                        'request_id' => $requestId,
                        'message' => 'Video processing request submitted successfully. Processing will continue asynchronously.',
                        'message_id' => $entity->getId(),
                        'status' => 'IN_QUEUE',
                    ];
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
     * Submit text-to-video request and return request ID
     */
    protected function submitTextToVideo(string $videoModel, Model $entity, array $params): ?string
    {
        // Get default values from app settings
        $defaultValues = $this->getDefaultVideoValues($entity->app, 'text-to-video');

        // Submit the video generation request
        $submitPayload = [
            'operation' => 'submit',
            'model' => $videoModel,
            'prompt' => $entity->message['prompt'] ?? '',
            'resolution' => $defaultValues['resolution'] ?? '720p',
        ];

        // Add optional webhook URL if configured
        $webhookUrl = $entity->app->get('PROMPT_VIDEO_WEBHOOK_URL');
        if ($webhookUrl) {
            $submitPayload['webhookUrl'] = $webhookUrl;
        }

        // Add other optional parameters based on model configuration
        if (isset($defaultValues['aspect_ratio'])) {
            $submitPayload['aspect_ratio'] = $defaultValues['aspect_ratio'];
        }
        if (isset($defaultValues['generate_audio'])) {
            $submitPayload['generate_audio'] = $defaultValues['generate_audio'];
        }
        if (isset($defaultValues['duration'])) {
            $submitPayload['duration'] = $defaultValues['duration'];
        }
        if (isset($defaultValues['negative_prompt'])) {
            $submitPayload['negative_prompt'] = $defaultValues['negative_prompt'];
        }
        if (isset($defaultValues['style'])) {
            $submitPayload['style'] = $defaultValues['style'];
        }
        if (isset($defaultValues['prompt_optimizer'])) {
            $submitPayload['prompt_optimizer'] = $defaultValues['prompt_optimizer'];
        }

        $submitResponse = $this->submitVideoRequest($submitPayload);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit video for processing: ' . json_encode($submitResponse));
        }

        return $submitResponse['request_id'];
    }

    /**
     * Submit image-to-video request and return request ID
     */
    protected function submitImageToVideo(string $imageUrl, string $videoModel, Model $entity, array $params): ?string
    {
        // Get default values from app settings
        $defaultValues = $this->getDefaultVideoValues($entity->app, 'image-to-video');

        // Submit the video generation request
        $submitPayload = [
            'operation' => 'submit',
            'model' => $videoModel,
            'image_url' => $imageUrl,
            'prompt' => $entity->message['prompt'] ?? '',
        ];

        // Add optional webhook URL if configured
        $webhookUrl = $entity->app->get('PROMPT_VIDEO_WEBHOOK_URL');
        if ($webhookUrl) {
            $submitPayload['webhookUrl'] = $webhookUrl;
        }

        // Add other optional parameters based on model configuration
        if (isset($defaultValues['prompt_optimizer'])) {
            $submitPayload['prompt_optimizer'] = $defaultValues['prompt_optimizer'];
        }
        if (isset($defaultValues['aspect_ratio'])) {
            $submitPayload['aspect_ratio'] = $defaultValues['aspect_ratio'];
        }
        if (isset($defaultValues['resolution'])) {
            $submitPayload['resolution'] = $defaultValues['resolution'];
        }
        if (isset($defaultValues['duration'])) {
            $submitPayload['duration'] = $defaultValues['duration'];
        }
        if (isset($defaultValues['negative_prompt'])) {
            $submitPayload['negative_prompt'] = $defaultValues['negative_prompt'];
        }

        $submitResponse = $this->submitVideoRequest($submitPayload);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit image-to-video for processing: ' . json_encode($submitResponse));
        }

        return $submitResponse['request_id'];
    }

    /**
     * Poll for video processing result with retries
     */
    protected function pollForVideoResult(string $requestId, string $videoModel, Apps $app): array
    {
        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                // Check status
                $statusResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($this->apiUrl, [
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
                    ])->post($this->apiUrl, [
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
     * Get default video values from app settings
     */
    protected function getDefaultVideoValues(Apps $app, string $type): array
    {
        $settings = $app->get('llm_list_video_categorization_dev');

        if (! $settings || ! is_array($settings)) {
            return [];
        }

        $videoKey = $type === 'text-to-video' ? 'fal-ai/text-to-video' : 'fal-ai/image-to-video';

        // Search through all categories for the video key
        foreach ($settings as $category) {
            if (! isset($category['value']) || ! is_array($category['value'])) {
                continue;
            }

            foreach ($category['value'] as $videoTypeConfig) {
                if (isset($videoTypeConfig['key']) && $videoTypeConfig['key'] === $videoKey) {
                    if (isset($videoTypeConfig['value']) && is_array($videoTypeConfig['value'])) {
                        // Find the first (default) model configuration
                        foreach ($videoTypeConfig['value'] as $modelConfig) {
                            if (isset($modelConfig['input_config']) && isset($modelConfig['isDefault']) && $modelConfig['isDefault']) {
                                return $this->extractDefaultsFromInputConfig($modelConfig['input_config']);
                            }
                        }

                        // If no default found, use the first one
                        if (! empty($videoTypeConfig['value']) && isset($videoTypeConfig['value'][0]['input_config'])) {
                            return $this->extractDefaultsFromInputConfig($videoTypeConfig['value'][0]['input_config']);
                        }
                    }
                }
            }
        }

        return [];
    }

    /**
     * Extract default values from input_config
     */
    private function extractDefaultsFromInputConfig(array $inputConfig): array
    {
        $defaults = [];

        foreach ($inputConfig as $key => $values) {
            // Skip comment fields
            if (strpos($key, '__') === 0 && strpos($key, '_comment__') !== false) {
                continue;
            }

            if (is_array($values) && ! empty($values)) {
                $defaults[$key] = $values[0];
            } elseif (! is_array($values)) {
                $defaults[$key] = $values;
            }
        }

        return $defaults;
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
     * Submit a video generation request
     */
    protected function submitVideoRequest(array $payload): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, $payload);

        Log::info('Video request submitted', [
            'url' => $this->apiUrl,
            'payload' => $payload,
            'response' => $response->json(),
        ]);
        return $response->json();
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
