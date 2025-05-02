<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * @todo move to promptmine namespace
 */
class OptimizeImageFromMessageActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) {
                //$messageContent = ! is_array($message->message) ? json_decode($message->message, true) : $message->message;
                $messageContent = $message->message;

                if (! isset($messageContent['image']) && ! isset($messageContent['ai_image'])) {
                    return [
                        'result' => false,
                        'message' => 'Message does not have an image url',
                    ];
                }

                // Safely retrieve the image URL based on message type
                if ($message->parent_id) {
                    // For child messages, use 'image' key
                    if (! isset($messageContent['image'])) {
                        return [
                            'result' => false,
                            'message' => 'Child message does not have an image url',
                        ];
                    }
                    $imageUrl = $messageContent['image'];
                } else {
                    // For parent messages, use 'ai_image.image' key
                    if (! isset($messageContent['ai_image']) || ! isset($messageContent['ai_image']['image'])) {
                        return [
                            'result' => false,
                            'message' => 'Parent message does not have a valid AI image url',
                        ];
                    }
                    $imageUrl = $messageContent['ai_image']['image'];
                }

                $tempFilePath = ImageOptimizerService::optimizeImageFromUrl($imageUrl);
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

                $filesystem = new FilesystemServices($app);
                $fileSystemRecord = $filesystem->upload($uploadedFile, $message->user);
                $defaultCompany = null;
                $defaultUser = null;

                $defaultCompanyBranchId = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
                if ($defaultCompanyBranchId) {
                    //to avoid duplicate tags for each message company
                    $defaultCompany = CompaniesBranches::getById($defaultCompanyBranchId)->company;
                    $defaultUser = $defaultCompany->user;
                }

                if (! $message->parent_id && isset($messageContent['ai_image'])) {
                    $tempMessageArray = $messageContent;
                    $tempMessageArray['ai_image'] = array_merge($messageContent['ai_image'], ['image' => $fileSystemRecord->url]);
                    $message->message = $tempMessageArray;
                    $message->addTag('image', $app, $defaultUser, $defaultCompany);
                    $message->saveOrFail();
                    $imageTitle = $messageContent['title'] ?? '';
                    // Update child messages too

                    foreach ($message->children as $childMessage) {
                        //$childMessageArray = is_array($childMessage->message) ? $childMessage->message : json_decode($childMessage->message, true);
                        $childMessageArray = $childMessage->message;
                        if (! is_array($childMessageArray) || ! array_key_exists('image', $childMessageArray)) {
                            continue;
                        }
                        $tempChildMessageArray = $childMessageArray;
                        $tempChildMessageArray['image'] = $fileSystemRecord->url;
                        if (! empty($imageTitle)) {
                            $tempChildMessageArray['title'] = $imageTitle;
                        }

                        //$childMessage->message = json_encode($tempChildMessageArray);
                        $childMessage->message = $tempChildMessageArray;
                        $childMessage->addTag('image', $app, $defaultUser, $defaultCompany);
                        $childMessage->saveOrFail();
                    }
                } elseif ($message->parent_id && isset($messageContent['image'])) {
                    $tempMessageArray = $messageContent;
                    $tempMessageArray['image'] = $fileSystemRecord->url;
                    //$message->message = json_encode($tempMessageArray);
                    $message->message = $tempMessageArray;
                    $message->addTag('image', $app, $defaultUser, $defaultCompany);
                    $message->saveOrFail();
                } else {
                    $message->addTag('text', $app, $defaultUser, $defaultCompany);
                }

                // Clean up the temporary file
                unlink($tempFilePath);

                return [
                    'result' => true,
                    'message' => 'Image optimized and uploaded',
                    'data' => $fileSystemRecord,
                    'message_id' => $message->getId(),
                ];
            },
            company: $message->company,
        );
    }
}
