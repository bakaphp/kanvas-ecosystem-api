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
    protected const int MAX_STATUS_CHECKS = 30;
    protected const int STATUS_CHECK_DELAY = 2;
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        sleep($app->get('PROMPT_VIDEO_WAIT_TIME') ?? 5);
        $this->app = $app;
        $this->apiUrl = $entity->app->get('PROMPT_VIDEO_API_URL');

        // Extract video model dynamically from message - use the full value as is
        $videoModel = $entity->message['ai_model']['value'] ?? 'fal-ai/veo3';
        $videoType = $entity->message['type'] ?? 'video-format';

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params, $videoModel, $videoType) {
                $entity->setPrivate();

                if (empty($this->apiUrl)) {
                    return [
                        'result' => false,
                        'message' => 'Video API URL not configured',
                    ];
                }

                $fileSystemRecord = null;
                $processedVideoUrl = null;
                $requestId = null;

                try {
                    // Determine if it's text-to-video or image-to-video based on hasFiles flag
                    $isImageToVideo = isset($entity->message['hasFiles']) && $entity->message['hasFiles'] === true;

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
                        list($fileSystemRecord, $processedVideoUrl, $requestId) = $this->processImageToVideo(
                            $imageUrl,
                            $videoModel,
                            $entity
                        );
                    } else {
                        // Process text-to-video
                        list($fileSystemRecord, $processedVideoUrl, $requestId) = $this->processTextToVideo(
                            $videoModel,
                            $entity
                        );
                    }

                    if ($fileSystemRecord === null) {
                        return [
                            'result' => false,
                            'model' => $videoModel,
                            'request_id' => $requestId,
                            'message' => 'Failed to retrieve processed video',
                        ];
                    }

                    // Create nugget message and send notification
                    return $this->finalizeProcessing(
                        $entity,
                        $fileSystemRecord,
                        $processedVideoUrl,
                        $params,
                        $requestId
                    );
                } catch (Exception $e) {
                    report($e);

                    return [
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Error processing video: ' . $e->getMessage(),
                    ];
                }
            },
            company: $company,
        );
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
     * Process text-to-video
     *
     * @return array [fileSystemRecord, processedVideoUrl, requestId]
     */
    protected function processTextToVideo(string $videoModel, Model $entity): array
    {
        // Get default values from app settings
        $defaultValues = $this->getDefaultVideoValues($entity->app, 'text-to-video');

        // Step 1: Submit the video generation request
        $submitPayload = [
            'operation' => 'submit',
            'model' => $videoModel, // Use the full model value as is
            'prompt' => $entity->message['prompt'] ?? '',
            'aspect_ratio' => $defaultValues['aspect_ratio'] ?? '16:9',
            'generate_audio' => $defaultValues['generate_audio'] ?? true,
            'duration' => $defaultValues['duration'] ?? '8s',
        ];

        $submitResponse = $this->submitVideoRequest($submitPayload);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit video for processing: ' . json_encode($submitResponse));
        }

        $requestId = $submitResponse['request_id'];

        // Step 2: Check processing status until complete
        $statusResponse = $this->checkProcessingStatus($requestId, $videoModel);

        if ($statusResponse['status'] !== 'COMPLETED') {
            throw new Exception('Video processing did not complete successfully: ' . json_encode($statusResponse));
        }

        // Step 3: Get the processed video result
        $resultResponse = $this->getProcessingResult($requestId, $videoModel);
        $processedVideoUrl = $this->extractVideoUrl($resultResponse);

        if ($processedVideoUrl === null) {
            throw new Exception('Failed to extract video URL from response: ' . json_encode($resultResponse));
        }

        // Download and upload video to our filesystem
        $fileSystemRecord = $this->downloadAndUploadVideo($processedVideoUrl, $entity);

        return [$fileSystemRecord, $processedVideoUrl, $requestId];
    }

    /**
     * Process image-to-video
     *
     * @return array [fileSystemRecord, processedVideoUrl, requestId]
     */
    protected function processImageToVideo(string $imageUrl, string $videoModel, Model $entity): array
    {
        // Get default values from app settings
        $defaultValues = $this->getDefaultVideoValues($entity->app, 'image-to-video');

        // Step 1: Submit the video generation request
        $submitPayload = [
            'operation' => 'submit',
            'model' => $videoModel, // Use the full model value as is
            'image_url' => $imageUrl,
            'prompt' => $entity->message['prompt'] ?? '',
            'prompt_optimizer' => $defaultValues['prompt_optimizer'] ?? true,
        ];

        $submitResponse = $this->submitVideoRequest($submitPayload);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit image-to-video for processing: ' . json_encode($submitResponse));
        }

        $requestId = $submitResponse['request_id'];

        // Step 2: Check processing status until complete
        $statusResponse = $this->checkProcessingStatus($requestId, $videoModel);

        if ($statusResponse['status'] !== 'COMPLETED') {
            throw new Exception('Image-to-video processing did not complete successfully: ' . json_encode($statusResponse));
        }

        // Step 3: Get the processed video result
        $resultResponse = $this->getProcessingResult($requestId, $videoModel);
        $processedVideoUrl = $this->extractVideoUrl($resultResponse);

        if ($processedVideoUrl === null) {
            throw new Exception('Failed to extract video URL from response: ' . json_encode($resultResponse));
        }

        // Download and upload video to our filesystem
        $fileSystemRecord = $this->downloadAndUploadVideo($processedVideoUrl, $entity);

        return [$fileSystemRecord, $processedVideoUrl, $requestId];
    }

    /**
     * Get default video values from app settings
     */
    protected function getDefaultVideoValues(Apps $app, string $type): array
    {
        $settingsKey = $type === 'text-to-video'
            ? 'llm_list_video_categorization_dev'
            : 'llm_list_image_to_video_categorization_dev';

        $settings = $app->get($settingsKey);

        if (! $settings || ! isset($settings['input_config'])) {
            return [];
        }

        $inputConfig = $settings['input_config'];
        $defaults = [];

        // Extract first value of each array as default
        foreach ($inputConfig as $key => $values) {
            if (is_array($values) && ! empty($values)) {
                $defaults[$key] = $values[0];
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

            $totalDelivery = new DistributeMessagesToUsersAction($entity, $this->app)->execute();
        } catch (InternalServerErrorException $e) {
            report($e);

            return [
                'result' => false,
                'message' => 'Error in notification to user',
                'exception' => $e,
            ];
        }

        $result = [
            'message' => 'Video processed successfully',
            'total_delivery' => $totalDelivery,
            'result' => true,
            'user_id' => $entity->user->getId(),
            'message_data' => $entity->message,
            'message_id' => $entity->getId(),
            'nugget_message_id' => $createNuggetMessage->getId(),
        ];

        // Add processed video URL and request ID if they exist
        if ($processedVideoUrl !== null) {
            $result['processed_video_url'] = $processedVideoUrl;
        }

        if ($requestId !== null) {
            $result['request_id'] = $requestId;
        }

        // Turn type to prompt
        $entity->message_types_id = MessageType::fromApp($entity->app)->where('verb', 'prompt')->firstOrFail()->getId();
        $entity->update();

        return $result;
    }

    /**
     * Submit a video generation request
     */
    protected function submitVideoRequest(array $payload): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, $payload);

        return $response->json();
    }

    /**
     * Check the processing status of a submitted video
     */
    protected function checkProcessingStatus(string $requestId, string $videoModel): array
    {
        $attempts = 0;
        $statusResponse = [];

        while ($attempts < self::MAX_STATUS_CHECKS) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, [
                'operation' => 'status',
                'requestId' => $requestId,
                'model' => $videoModel, // Use the full model value as is
                'logs' => true,
            ]);

            $statusResponse = $response->json();

            if ($statusResponse['status'] === 'COMPLETED') {
                break;
            }

            if ($statusResponse['status'] === 'FAILED') {
                throw new Exception('Video processing failed for request: ' . $requestId);
            }

            // Wait before checking again
            sleep(self::STATUS_CHECK_DELAY);
            $attempts++;
        }

        if ($attempts >= self::MAX_STATUS_CHECKS) {
            throw new Exception('Video processing timed out for request: ' . $requestId);
        }

        return $statusResponse;
    }

    /**
     * Get the result of a processed video
     */
    protected function getProcessingResult(string $requestId, string $videoModel): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'operation' => 'result',
            'requestId' => $requestId,
            'model' => $videoModel, // Use the full model value as is
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
