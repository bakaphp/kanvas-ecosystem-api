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
use Kanvas\Workflow\KanvasActivity;

class OptimizeImageFromMessageActivity extends KanvasActivity
{
    public $tries = 3;
    public $queue = 'default';

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (empty($message->message['ai_image']) || empty($message->message['ai_image']['image'])) {
            return [
                'result' => false,
                'message' => 'Message does not have an AI image',
            ];
        }

        $tempFilePath = ImageOptimizerService::optimizeImageFromUrl($message->message['ai_image']['image']);
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

        if (array_key_exists('image', $message->message['ai_image'])) {
            $tempMessageArray = $message->message;
            $tempMessageArray['ai_image'] = array_merge($message->message['ai_image'], ['image' => $fileSystemRecord->url]);
            $message->message = $tempMessageArray;
            $message->addTag('image', $app, $defaultUser, $defaultCompany);
            $message->saveOrFail();
            $imageTitle = $message->message['title'];
            // Update child messages too

            foreach ($message->children as $childMessage) {
                $childMessageArray = json_decode($childMessage->message, true);
                if (! is_array($childMessageArray) || ! array_key_exists('image', $childMessageArray)) {
                    continue;
                }
                $tempChildMessageArray = $childMessageArray;
                $tempChildMessageArray['image'] = $fileSystemRecord->url;
                $tempChildMessageArray['title'] = $imageTitle;

                $childMessage->message = json_encode($tempChildMessageArray);
                $childMessage->addTag('image', $app, $defaultUser, $defaultCompany);
                $childMessage->saveOrFail();
            }
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
    }
}
