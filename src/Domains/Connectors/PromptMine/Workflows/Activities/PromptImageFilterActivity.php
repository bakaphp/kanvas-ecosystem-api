<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Connectors\PromptMine\Actions\CreateNuggetMessageAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Illuminate\Http\UploadedFile;
use Kanvas\Social\Messages\Notifications\CustomMessageNotification;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Exceptions\InternalServerErrorException;
use Override;
use finfo;

class PromptImageFilterActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?string $apiUrl = null;
    protected const int MAX_STATUS_CHECKS = 30;
    protected const int STATUS_CHECK_DELAY = 2;
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $pushTemplate = $params['push_template'];
        $messageFiles = $entity->getFiles();
        $this->apiUrl = $entity->app->get('PROMPT_IMAGE_API_URL');

        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
            $company = $branch->company;
        } catch (ModelNotFoundException $e) {
            $company = $entity->company;
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($entity) use ($messageFiles, $pushTemplate) {
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

                try {
                    // Step 1: Submit the image for processing
                    $submitResponse = $this->submitImage($fileUrl);

                    if (! isset($submitResponse['request_id'])) {
                        return [
                            'result' => false,
                            'message' => 'Failed to submit image for processing',
                        ];
                    }

                    $requestId = $submitResponse['request_id'];

                    // Step 2: Check processing status until complete
                    $statusResponse = $this->checkProcessingStatus($requestId);

                    if ($statusResponse['status'] !== 'COMPLETED') {
                        return [
                            'result' => false,
                            'message' => 'Image processing did not complete successfully',
                        ];
                    }

                    // Step 3: Get the processed image result
                    $resultResponse = $this->getProcessingResult($requestId);

                    if (! isset($resultResponse['data']['image']['url'])) {
                        return [
                            'result' => false,
                            'message' => 'Failed to retrieve processed image',
                        ];
                    }

                    $processedImageUrl = $resultResponse['data']['image']['url'];
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

                    // Step 4: Create a new nugget message with the processed image
                    $createNuggetMessage = (new CreateNuggetMessageAction(
                        parentMessage: $entity,
                        messageData: [
                            'title' => $entity->message->message['title'],
                            'type' => 'image-format',
                            'image' => $entity->app->get('cloud-cdn') . '/' . $fileSystemRecord->path,
                        ],
                    ))->execute();

                    $endViaList = array_map(
                        [NotificationChannelEnum::class, 'getNotificationChannelBySlug'],
                        $params['via'] ?? ['database']
                    );


                    $config = [
                        'email_template' => null,
                        'push_template' => $pushTemplate,
                        'app' => $entity->app,
                        'company' => $entity->company,
                        'message' => "Your image for $entity->message['title'] has been processed",
                        'title' => "Image Processed",
                        'metadata' => $entity->getMessage(),
                        'via' => $endViaList,
                        'message_owner_id' => $entity->user->getId(),
                        'message_id' => $entity->getId(),
                        'destination_id' => $entity->getId(),
                        'destination_type' => 'MESSAGE',
                        'destination_event' => 'NEW_MESSAGE',
                    ];
            
                    try {
                        $newMessageNotification = new CustomMessageNotification(
                            $entity,
                            $config,
                            $config['via']
                        );
            
                        $entity->user->notify($newMessageNotification);
                    } catch (InternalServerErrorException $e) {
                        return [
                            'result' => false,
                            'message' => 'Error in notification to user',
                            'exception' => $e,
                        ];
                    }

                    return [
                        'message' => 'Image processed successfully',
                        'result' => true,
                        'user_id' => $entity->user->getId(),
                        'message_data' => $entity->message,
                        'message_id' => $entity->getId(),
                        'nugget_message_id' => $createNuggetMessage->getId(),
                        'processed_image_url' => $processedImageUrl,
                        'original_image_url' => $fileUrl,
                        'request_id' => $requestId,
                    ];
                } catch (Exception $e) {
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
     * Submit an image for processing
     */
    protected function submitImage(string $imageUrl): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'operation' => 'submit',
            'image_url' => $imageUrl,
            'model' => 'fal-ai/ghiblify',
        ]);

        return $response->json();
    }

    /**
     * Check the processing status of a submitted image
     */
    protected function checkProcessingStatus(string $requestId): array
    {
        $attempts = 0;
        $statusResponse = [];

        while ($attempts < self::MAX_STATUS_CHECKS) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl, [
                'operation' => 'status',
                'requestId' => $requestId,
                'model' => 'fal-ai/ghiblify',
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
    protected function getProcessingResult(string $requestId): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'operation' => 'result',
            'requestId' => $requestId,
            'model' => 'fal-ai/ghiblify',
        ]);

        return $response->json();
    }
}
