<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use finfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\PromptMine\Actions\CreateNuggetMessageAction;
use Kanvas\Connectors\PromptMine\Actions\MessageOrderFulfillmentAction;
use Kanvas\Connectors\PromptMine\Notifications\ImageProcessingPushNotification;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Social\Messages\Actions\CheckMessagePostLimitAction;
use Kanvas\Social\Messages\Actions\DistributeMessagesToUsersAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Throwable;

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

        sleep($app->get('PROMPT_IMAGE_WAIT_TIME') ?? 10);
        $entity->refresh();
        $messageFiles = $this->getFilesWithRetry($entity);
        $this->app = $app;
        $this->apiUrl = $entity->app->get('PROMPT_IMAGE_API_URL');
        $this->openaiApiUrl = $entity->app->get('PROMPT_IMAGE_API_URL_OPENAI');
        $imageFilter = Str::of($entity->message['ai_model']['value'] ?? 'cartoonify')->replace('fal-ai/', '')->toString();
        $imageFilterName = $entity->message['ai_model']['name'] ?? 'cartoonify';

        $isOpenAi = Str::contains($imageFilter, 'gpt');
        $isGeminiBanana = Str::contains($imageFilterName, 'Banana');

        $company = $this->getCompany($app, $entity);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($messageFiles, $params, $imageFilter, $isOpenAi, $isGeminiBanana) {
                $entity->setPrivate();

                if (! empty($this->validateImageLimit($entity, $params))) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Image limit validation failed',
                    ]);
                }

                if (empty($this->apiUrl)) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'API URL not configured',
                    ]);
                }

                if ($messageFiles->isEmpty()) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'Message does not have any files',
                    ]);
                }

                // Deduct user credit based on the selected image filter
                new MessageOrderFulfillmentAction($entity)->execute('image');

                $fileUrl = $messageFiles->first()->url;
                $fileSystemRecord = null;
                $processedImageUrl = null;
                $requestId = null;

                if ($messageFiles->count() > 1) {
                    //lets add them to params since this is optional
                    $params['additional_images'] = $messageFiles->slice(1)->map(fn ($file) => $file->url)->toArray();
                }

                try {
                    // Process image based on the model type
                    if ($isOpenAi) {
                        $fileSystemRecord = $this->processImageWithOpenAI($fileUrl, $entity->message['prompt'], $entity, $params);
                        if ($fileSystemRecord === null) {
                            return $this->failWorkflow([
                                'result' => false,
                                'filter' => $imageFilter,
                                'message' => 'Failed to retrieve processed image',
                            ]);
                        }
                    } elseif ($isGeminiBanana) {
                        // Process with Gemini-Nano-Banana
                        $fileSystemRecord = $this->processImageWithGeminiBanana($fileUrl, $entity->message['prompt'] ?? '', $entity, $imageFilter, $params);
                        if ($fileSystemRecord === null) {
                            return $this->failWorkflow([
                                'result' => false,
                                'filter' => $imageFilter,
                                'message' => 'Failed to retrieve processed image',
                            ]);
                        }
                        // For Gemini-Nano-Banana, we don't have a separate processed URL since we upload directly
                        $processedImageUrl = null;
                    } else {
                        // Process with fal.ai
                        list($fileSystemRecord, $processedImageUrl, $requestId) = $this->processImageWithFalAi(
                            $fileUrl,
                            $imageFilter,
                            $entity,
                            $params
                        );

                        if ($fileSystemRecord === null) {
                            return $this->failWorkflow([
                                'result' => false,
                                'filter' => $imageFilter,
                                'request_id' => $requestId,
                                'message' => 'Failed to retrieve processed image',
                            ]);
                        }
                    }

                    // Create nugget message and send notification - common for both methods
                    return $this->finalizeProcessing(
                        $entity,
                        $fileSystemRecord,
                        $fileUrl,
                        $processedImageUrl,
                        $params,
                        $requestId,
                        $imageFilter
                    );
                } catch (Exception $e) {
                    //report($e);

                    return $this->failWorkflow([
                        'result' => false,
                        'message_id' => $entity->getId(),
                        'message' => 'Error processing image: ' . $e->getMessage(),
                    ]);
                }
            },
            company: $company,
        );
    }

    protected function getFilesWithRetry(Model $entity, int $maxAttempts = 5, int $delaySeconds = 2): Collection
    {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $entity->refresh();
            $files = $entity->getFiles();

            if ($files->isNotEmpty()) {
                return $files;
            }

            $attempts++;
            if ($attempts < $maxAttempts) {
                sleep($delaySeconds);
            }
        }

        return new Collection();
    }

    public function validateImageLimit(Message $message, array $params): array
    {
        try {
            (new CheckMessagePostLimitAction(
                message: $message,
                //messageTypeId: MessageType::fromApp($message->app)->where('verb', 'prompt')->firstOrFail()->getId(),
                messageJsonFilters: ['type' => 'image-format']
            ))->execute();
        } catch (Throwable $e) {
            if (! Str::contains($e->getMessage(), 'Your daily limit has been reached')) {
                report($e);
            }

            try {
                $endViaList = array_map(
                    [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                    $params['via'] ?? ['database']
                );
                $errorProcessingImageNotification = new ImageProcessingPushNotification(
                    user: $message->user,
                    entity: $message,
                    message: 'You have reached your daily image generation limit.',
                    title: 'Daily Limit Reached',
                    via: $endViaList,
                    templates: [
                        'email_template' => $params['email_template'],
                        'push_template' => $params['push_template'],
                    ],
                );

                //send to the user profile when it fails
                $errorProcessingImageNotification->setData([
                    'destination_id' => $message->getId(),
                    'destination_type' => 'USER',
                    'destination_event' => 'FOLLOWING',
                ]);
                $message->user->notify($errorProcessingImageNotification);
            } catch (Throwable $e) {
                report($e);
            }
            $message->delete();

            return [
                'result' => false,
                'message_id' => $message->getId(),
                'message' => 'Error checking message post limit: ' . $e->getMessage(),
            ];
        }

        return [];
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
     * Process image with fal.ai
     *
     * @return array [fileSystemRecord, processedImageUrl, requestId]
     */
    protected function processImageWithFalAi(string $fileUrl, string $imageFilter, Model $entity, array $params): array
    {
        // Step 1: Submit the image for processing
        $model = 'fal-ai/';
        $submitResponse = $this->submitImage($this->apiUrl, $fileUrl, $imageFilter, $entity->message['prompt'] ?? '', $model, $params)->json();

        if (! isset($submitResponse['request_id'])) {
            throw new Exception('Failed to submit image for processing: ' . json_encode($submitResponse));
        }

        $requestId = $submitResponse['request_id'];

        // Step 2: Check processing status until complete
        $statusResponse = $this->checkProcessingStatus($requestId, $imageFilter);

        if ($statusResponse['status'] !== 'COMPLETED') {
            $this->sendFailNotification(
                $entity,
                "Just a heads-up! Your last creation had a hiccup. Your credit wasn't used, feel free to try again.",
                $params
            );

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
                    $params['email_template'] = 'image-processing-failure-generic-error';
                    $this->sendFailNotification(
                        $entity,
                        "Just a heads-up! Your last creation had a hiccup. Your credit wasn't used, feel free to try again.",
                        $params
                    );

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
                message: "Your recent creation couldn't be completed as it didn't comply with the content provider’s policies.",
                title: 'Error processing image',
                via: $endViaList,
                templates: [
                    'email_template' => 'image-processing-failure-policy-violation',
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
     * Process image with Gemini-Nano-Banana
     */
    protected function processImageWithGeminiBanana(string $imageUrl, string $prompt, Model $entity, string $imageFilter, array $params): ?Filesystem
    {
        $apiUrl = str_replace('api/image/fal-ai/image-to-image', '', $this->apiUrl);
        $apiUrl = rtrim($apiUrl, '/') . '/api/image/Gemini-Nano-Banana/i2i';

        $response = $this->submitImage($apiUrl, $imageUrl, $imageFilter, $prompt, '', $params);
        $responseData = $response->json();

        if (! $response->successful()) {
            $params['email_template'] = 'image-processing-failure-generic-error';
            $this->sendFailNotification(
                $entity,
                "Just a heads-up! Your last creation had a hiccup. Your credit wasn't used, feel free to try again.",
                $params
            );

            throw new Exception('Gemini Banana API request failed: ' . json_encode($responseData));
        }

        $processedImageUrl = null;
        if (isset($responseData['0']['url'])) {
            $processedImageUrl = $responseData['0']['url'];
        } elseif (isset($response[0]['url'])) {
            $processedImageUrl = $responseData[0]['url'];
        }

        if (! $processedImageUrl) {
            throw new Exception('Failed to extract image URL from Gemini Banana response: ' . json_encode($responseData));
        }

        // Optimize and upload the processed image
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

        return $filesystem->upload($uploadedFile, $entity->user);
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
        ?string $requestId = null,
        ?string $imageFilter = null
    ): array {
        // Lets generate a new title using ai if no title is set
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
        $isRemix = $entity->message['remix_parent_id'] ?? false;
        $user = Users::getById($entity->users_id);
        $user->set('images_generated', ($user->get('images_generated', 0) + 1), true);

        // Create a new nugget message with the processed image
        $cdnImageUrl = $entity->app->get('cloud-cdn') . '/' . $fileSystemRecord->path;
        $createNuggetMessage = (new CreateNuggetMessageAction(
            parentMessage: $entity,
            messageData: [
                'title' => trim($title),
                'type' => 'image-format',
                'image' => $cdnImageUrl,
            ],
            messageTypeVerb: 'chat-response',
        ))->execute();

        $messageCopy = $entity->message;
        if ($isRemix) {
            $messageCopy['ai_image'] = array_merge(
                ['ai_model' => $messageCopy['ai_model']],
                [
                    'nugget' => $cdnImageUrl,
                    'title' => trim($title),
                    'type' => 'image-format',
                ]
            );
        } else {
            $messageCopy['ai_image'] = $cdnImageUrl;
        }
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
                message: addslashes("Your image for {$title} has been processed"),
                title: 'Image Processed',
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
            'message' => 'Image processed successfully',
            'total_delivery' => $totalDelivery,
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
        //$entity->disableWorkflows();
        $entity->update();

        return $result;
    }

    /**
     * Submit an image for processing
     */
    protected function submitImage(string $apiUrl, string $imageUrl, string $imageFilter, string $prompt, string $model, array $params): Response
    {
        if (isset($params['additional_images']) && ! empty($params['additional_images'])) {
            $params['additional_images'][] = $imageUrl;
            $imageUrl = array_values($params['additional_images']);
        }
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($apiUrl, [
            'operation' => 'submit',
            'image_url' => $imageUrl,
            'model' => $model . $imageFilter,
            'prompt' => $prompt,
        ]);

        return $response;
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

    private function generateTitleByPrompt(string $prompt): string
    {
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.0-flash')
            ->withPrompt('Generate a short concise title from this prompt: ' . $prompt . '.Choose just one title, dont give me suggestions')
            ->generate();

        return str_replace(['```', 'json'], '', $response->text);
    }

    private function sendFailNotification(Message $entity, string $message, array $params): void
    {
        $endViaList = array_map(
            [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
            $params['via'] ?? ['database']
        );
        $errorProcessingImageNotification = new ImageProcessingPushNotification(
            user: $entity->user,
            entity: $entity,
            message: $message,
            title: 'Error processing image',
            via: $endViaList,
            templates: [
                'email_template' => 'image-processing-failure-generic-error',
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
    }
}
