<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Activities;

use Baka\Contracts\AppInterface;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Google\Services\MapStaticApiService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Workflow\KanvasActivity;

class CovertMapsCoordinatesToImageActivity extends KanvasActivity
{
    //public $tries = 3;
    public $queue = 'default';

    public function execute(Model $message, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $messageContent = ! is_array($message->message) ? json_decode($message->message, true) : $message->message;

        if (! array_key_exists('coordinates', $messageContent) || empty($messageContent['coordinates'])) {
            return [
            'result' => false,
            'message' => 'Coordinates not found on message body',
            'activity' => self::class,
            'message_id' => $message->getId(),
            ];
        }
        
        $latitude = $messageContent['coordinates']['latitude'];
        $longitude = $messageContent['coordinates']['longitude'];
        $tempFilePath = MapStaticApiService::getImageFromCoordinates($latitude, $longitude);
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

        try {
            $tempMessageArray = $messageContent;
            $tempMessageArray['image'] = $fileSystemRecord->url;
            $message->message = $tempMessageArray;
            $message->saveOrFail();
        } catch (\Throwable $th) {
            Log::error('Failed to save message', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
        }

        // Clean up the temporary file
        unlink($tempFilePath);

        return [
            'result' => true,
            'message' => 'Image Url converted to Kanvas Filesystem',
            'activity' => self::class,
            'data' => $fileSystemRecord,
            'message_id' => $message->getId(),
        ];
    }
}
