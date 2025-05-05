<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\CreateNuggetMessageAction;
use Kanvas\Connectors\PromptMine\Notifications\ImageProcessingPushNotification;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PromptImageFilterActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?string $apiUrl = null;
    protected ?string $openaiApiUrl = null;
    protected ?Apps $app = null;
    protected const int MAX_STATUS_CHECKS = 30;
    protected const int STATUS_CHECK_DELAY = 2;
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        sleep($app->get('PROMPT_IMAGE_WAIT_TIME') ?? 5);
        $messageFiles = $entity->getFiles();
        $this->app = $app;
        $this->apiUrl = $entity->app->get('PROMPT_IMAGE_API_URL');
        $this->openaiApiUrl = $entity->app->get('PROMPT_IMAGE_API_URL_OPENAI');
        $imageFilter = Str::of($entity->message['ai_model']['value'] ?? 'cartoonify')->replace('fal-ai/', '')->toString();

        $isOpenAi = Str::contains($imageFilter, 'gpt');

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity) use ($messageFiles, $params, $imageFilter, $isOpenAi) {
                $entity->setPrivate();

                if (empty($this->apiUrl)) {
                    return [
                        'result' => false,
                        'message' => 'API URL not configured',
                    ];
                }

                if ($messageFiles->isEmpty()) {
                    return [
                        'result' => false,
                        'message' => 'Message does not have any files',
                    ];
                }

                $fileUrl = $messageFiles->first()->url;
                $fileSystemRecord = null;
                $processedImageUrl = null;
                $requestId = null;

                try {
                    // Process image based on the model type
                    if ($isOpenAi) {
                        $fileSystemRecord = $this->processImageWithOpenAI($fileUrl, $entity->message['prompt'], $entity, $params);
                        if ($fileSystemRecord === null) {
                            return [
                                'result' => false,
                                'filter' => $imageFilter,
                                'message' => 'Failed to retrieve processed image',
                            ];
                        }
                    } else {
                        // Process with fal.ai
                        list($fileSystemRecord, $processedImageUrl, $requestId) = $this->processImageWithFalAi(
                            $fileUrl,
                            $imageFilter,
                            $entity
                        );

                        if ($fileSystemRecord === null) {
                            return [
                                'result' => false,
                                'filter' => $imageFilter,
                                'request_id' => $requestId,
                                'message' => 'Failed to retrieve processed image',
                            ];
                        }
                    }

                    // Create nugget message and send notification - common for both methods
                    return $this->finalizeProcessing(
                        $entity,
                        $fileSystemRecord,
                        $fileUrl,
                        $processedImageUrl,
                        $params,
                        $requestId
                    );
                } catch (Exception $e) {
                    report($e);

                    return [
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Error processing image: ' . $e->getMessage(),
                    ];
                }
            },
            company: $company,
        );
    }

    /**
     * Get the company for this workflow
     */
    protected function getCompany(AppInterface $app, Model $entity): object
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
     * Process image with fal.ai
     *
     * @return array [fileSystemRecord, processedImageUrl, requestId]
     */
    protected function processImageWithFalAi(string $fileUrl, string $imageFilter, Model $entity): array
    {
        // Step 1: Submit the image for processing
        $submitResponse = $this->submitImage($fileUrl, $imageFilter);

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit image for processing: ' . json_encode($submitResponse));
        }

        $requestId = $submitResponse['request_id'];

        // Step 2: Check processing status until complete
        $statusResponse = $this->checkProcessingStatus($requestId, $imageFilter);

        if ($statusResponse['status'] !== 'COMPLETED') {
            throw new Exception('Image processing did not complete successfully: ' . json_encode($statusResponse));
        }

        // Step 3: Get the processed image result
        $resultResponse = $this->getProcessingResult($requestId, $imageFilter);
        $processedImageUrl = $this->extractImageUrl($resultResponse);

        if ($processedImageUrl === null) {
            throw new Exception('Failed to extract image URL from response: ' . json_encode($resultResponse));
        }

        $tempFilePath = ImageOptimizerService::optimizeImageFromUrl($processedImageUrl);
        $fileName = basename($tempFilePath);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempFilePath);

        $uploadedFile = new UploadedFile(
            $tempFilePath,
            $fileName,
            $mimeType,
            null,
            true
        );

        $filesystem = new FilesystemServices($entity->app);
        $fileSystemRecord = $filesystem->upload($uploadedFile, $entity->user);

        return [$fileSystemRecord, $processedImageUrl, $requestId];
    }

    /**
     * Process image with OpenAI
     */
    protected function processImageWithOpenAI(string $imageUrl, string $prompt, Model $entity, array $params = []): ?Filesystem
    {
        // Download the image file
        $imageContents = file_get_contents($imageUrl);
        $filename = basename(parse_url($imageUrl, PHP_URL_PATH));

        if ($imageContents === false) {
            throw new Exception("Failed to download image from URL: {$imageUrl}");
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'openai_img_');
        file_put_contents($tempFile, $imageContents);

        // Get the file's mime type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tempFile);

        // Set up retry mechanism
        $maxRetries = 3;
        $retryDelay = 2; // seconds
        $attempt = 0;
        $success = false;
        $response = null;

        while ($attempt < $maxRetries && ! $success) {
            try {
                // Create a multipart request with extended timeout (180 seconds = 3 minutes)
                $response = Http::timeout(200)
                    ->attach(
                        'image',
                        file_get_contents($tempFile),
                        basename($imageUrl),
                        ['Content-Type' => $mimeType]
                    )
                    ->post($this->openaiApiUrl, [
                        'model' => 'gpt-image-1',
                        'prompt' => $prompt,
                    ]);

                // If we get here, we got a response without timeout
                $success = true;
            } catch (Exception $e) {
                $attempt++;

                // If we're out of retries, rethrow the exception
                if ($attempt >= $maxRetries) {
                    throw new Exception("Image processing failed after {$maxRetries} attempts: " . $e->getMessage());
                }

                // Log the retry attempt
                report(new Exception("Image processing attempt {$attempt} failed: " . $e->getMessage() . ". Retrying in {$retryDelay} seconds."));

                // Wait before retrying
                sleep($retryDelay);

                // Increase the delay for next attempt (exponential backoff)
                $retryDelay *= 2;
            }
        }

        // Delete the original temporary file
        @unlink($tempFile);

        if (! $response->successful()) {
            $endViaList = array_map(
                [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                $params['via'] ?? ['database']
            );
            $errorProcessingImageNotification = new ImageProcessingPushNotification(
                user: $entity->user,
                entity: $entity,
                message: 'Your image could not be processed because it violated our content policy. Please try again with a different image.',
                title: 'Error processing image',
                via: $endViaList,
                templates: [
                    'email_template' => $params['email_template'],
                    'push_template' => $params['push_template'],
                ],
            );

            //send to the user profile when it fails
            $errorProcessingImageNotification->setData([
                'destination_id' => $entity->getId(),
                'destination_type' => 'USER',
                'destination_event' => 'FOLLOWING',
            ]);
            $entity->user->notify($errorProcessingImageNotification);
            $entity->delete();

            throw new Exception('OpenAI API request failed: ' . $response->body());
        }

        // Parse the response
        $responseData = $response->json();

        // Extract the base64 image data from the response
        $base64ImageData = null;

        if (isset($responseData[0]['b64_json'])) {
            $base64ImageData = $responseData[0]['b64_json'];
        } elseif (isset($responseData['data']) && isset($responseData['data']['b64_json'])) {
            $base64ImageData = $responseData['data']['b64_json'];
        } elseif (isset($responseData['b64_json'])) {
            $base64ImageData = $responseData['b64_json'];
        }

        if (! $base64ImageData) {
            // Log the entire response structure to help diagnose the issue
            report(new Exception('Unexpected OpenAI API response format: ' . json_encode($responseData)));

            return null;
        }

        $filesystemServices = new FilesystemServices($this->app);

        return $filesystemServices->createFileSystemFromBase64(
            $base64ImageData,
            $filename,
            $entity->user
        );
    }

    /**
     * Finalize the processing by creating a nugget message and sending notification
     */
    protected function finalizeProcessing(
        Model $entity,
        Filesystem $fileSystemRecord,
        string $originalImageUrl,
        ?string $processedImageUrl = null,
        array $params = [],
        ?string $requestId = null
    ): array {
        $title = $entity->message['title'] ?? $entity->message['prompt'];

        // Create a new nugget message with the processed image
        $cdnImageUrl = $entity->app->get('cloud-cdn') . '/' . $fileSystemRecord->path;
        $createNuggetMessage = (new CreateNuggetMessageAction(
            parentMessage: $entity,
            messageData: [
                'title' => $title,
                'type' => 'image-format',
                'image' => $cdnImageUrl,
            ],
        ))->execute();

        $messageCopy = $entity->message;
        $messageCopy['ai_image'] = $cdnImageUrl;
        $entity->message = $messageCopy;
        $entity->is_public = 1;
        $entity->save();

        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $params['via'] ?? ['database']
        );

        try {
            // Send notification to the user
            $newMessageNotification = new ImageProcessingPushNotification(
                user: $entity->user,
                entity: $entity,
                message: "Your image for {$title} has been processed",
                title: 'Image Processed',
                via: $endViaList,
                templates: [
                    'email_template' => $params['email_template'],
                    'push_template' => $params['push_template'],
                ],
            );
            $entity->user->notify($newMessageNotification);
        } catch (InternalServerErrorException $e) {
            report($e);

            return [
                'result' => false,
                'message' => 'Error in notification to user',
                'exception' => $e,
            ];
        }

        $result = [
            'message' => 'Image processed successfully',
            'result' => true,
            'user_id' => $entity->user->getId(),
            'message_data' => $entity->message,
            'message_id' => $entity->getId(),
            'nugget_message_id' => $createNuggetMessage->getId(),
            'original_image_url' => $originalImageUrl,
        ];

        // Add processed image URL and request ID if they exist (for fal-ai processing)
        if ($processedImageUrl !== null) {
            $result['processed_image_url'] = $processedImageUrl;
        }

        if ($requestId !== null) {
            $result['request_id'] = $requestId;
        }

        //turn type to prompt
        $entity->message_types_id = MessageType::fromApp($entity->app)->where('verb', 'prompt')->firstOrFail()->getId();
        $entity->disableWorkflows();
        $entity->update();

        return $result;
    }

    /**
     * Submit an image for processing
     */
    protected function submitImage(string $imageUrl, string $imageFilter): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'operation' => 'submit',
            'image_url' => $imageUrl,
            'model' => 'fal-ai/' . $imageFilter,
        ]);

        return $response->json();
    }

    /**
     * Check the processing status of a submitted image
     */
    protected function checkProcessingStatus(string $requestId, string $imageFilter): array
    {
        $attempts = 0;
        $statusResponse = [];

        while ($attempts < self::MAX_STATUS_CHECKS) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, [
                'operation' => 'status',
                'requestId' => $requestId,
                'model' => 'fal-ai/' . $imageFilter,
                'logs' => true,
            ]);

            $statusResponse = $response->json();

            if ($statusResponse['status'] === 'COMPLETED') {
                break;
            }

            if ($statusResponse['status'] === 'FAILED') {
                throw new Exception('Image processing failed for this request' . $requestId);
            }

            // Wait before checking again
            sleep(self::STATUS_CHECK_DELAY);
            $attempts++;
        }

        if ($attempts >= self::MAX_STATUS_CHECKS) {
            throw new Exception('Image processing timed out' . $requestId);
        }

        return $statusResponse;
    }

    /**
     * Get the result of a processed image
     */
    protected function getProcessingResult(string $requestId, string $imageFilter): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'operation' => 'result',
            'requestId' => $requestId,
            'model' => 'fal-ai/' . $imageFilter,
        ]);

        return $response->json();
    }

    /**
     * Extract image URL from the result response
     */
    private function extractImageUrl(array $resultResponse): ?string
    {
        // Check for data.image.url format
        if (isset($resultResponse['data']['image']['url'])) {
            return $resultResponse['data']['image']['url'];
        }

        // Check for data.images[0].url format
        if (
            isset($resultResponse['data']['images']) &&
            is_array($resultResponse['data']['images']) &&
            ! empty($resultResponse['data']['images']) &&
            isset($resultResponse['data']['images'][0]['url'])
        ) {
            return $resultResponse['data']['images'][0]['url'];
        }

        return null;
    }
}
