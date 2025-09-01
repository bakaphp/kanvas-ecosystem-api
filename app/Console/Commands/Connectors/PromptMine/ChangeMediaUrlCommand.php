<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Illuminate\Http\UploadedFile;
use finfo;
use Kanvas\Users\Models\Users;

class ChangeMediaUrlCommand extends Command
{
    private const OLD_MEDIA_URL = 'https://s3.amazonaws.com/mc-canvas/';
    private const CDN_URL = 'https://cdn.promptmine.ai/';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:change-media-url {appId=78} {messageType=588} {companyId=2626}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import image prompts from Google Sheets document.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $appId = (int) $this->argument('appId');
        $messageType = (int) $this->argument('messageType');
        $companyId = (int) $this->argument('companyId');

        $app = Apps::find($appId);
        $messageType = MessageType::fromApp($app)->where('id', $messageType)->firstOrFail();
        $company = Companies::where('id', $companyId)->where('is_deleted', 0)->firstOrFail();
        $this->refactorMediaUrl($app, $messageType, $company);
    }

    private function refactorMediaUrl(Apps $app, MessageType $messageType, Companies $company): void
    {
        Message::fromApp($app)
            ->where('companies_id', $company->getId())
            ->where('message_types_id', $messageType->getId())
            ->where('is_deleted', 0)
            ->orderBy('id', 'DESC')
            ->chunk(100, function ($messages) {
                foreach ($messages as $message) {
                    DB::beginTransaction();
                    try {
                        echo ('Updating Message ID ' . $message->getId() . PHP_EOL);
                        Log::info(sprintf('Updating Message ID %d:', $message->getId()));

                        $this->toCdnUrl($message);
                        DB::commit();
                        echo ('Successfully updated Message ID ' . $message->getId() . PHP_EOL);
                        Log::info(sprintf('Successfully updated Message ID %d:', $message->getId()));
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        echo ('Failed to update Message ID ' . $message->getId() . ': ' . $e->getMessage() . PHP_EOL);
                        Log::error(sprintf('Failed to update Message ID %d: %s', $message->getId(), $e->getMessage()));
                    }
                }
            });
    }

    private function toCdnUrl(Message $message): void
    {
        $messageData = is_array($message->message) ? $message->message : json_decode($message->message, true);
        match ($messageData['type']) {
            'video-format' => $messageData['video'] = (function () use ($messageData, $message) {
                if (!isset($messageData['video']) || strpos($messageData['video'], self::OLD_MEDIA_URL) === false) {
                    $messageData['video'] = $this->uploadToS3($messageData['video'], $messageData['type'], $message->user, $message->app, $message->company);
                }
                return str_replace(self::OLD_MEDIA_URL, self::CDN_URL, $messageData['video']);
            })(),
            'image-format' => $messageData['image'] = (function () use ($messageData, $message) {
                if (!isset($messageData['image']) || strpos($messageData['image'], self::OLD_MEDIA_URL) === false) {
                    $messageData['image'] = $this->uploadToS3($messageData['image'], $messageData['type'], $message->user, $message->app, $message->company);
                }
                return str_replace(self::OLD_MEDIA_URL, self::CDN_URL, $messageData['image']);
            })(),
            default => null,
        };

        $message->message = $messageData;
        $message->save();
    }

    private function uploadToS3(string $mediaUrl, string $type, Users $user, Apps $app, Companies $company): string
    {
        $tempFilePath = match ($type) {
            'image-format' => ImageOptimizerService::optimizeImageFromUrl($mediaUrl),
            'video-format' => FilesystemServices::downloadImageFromUrl($mediaUrl),
        };

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

        $filesystem = new FilesystemServices($app, $company);
        $fileSystemRecord = $filesystem->upload($uploadedFile, $user);
        return $fileSystemRecord->url;
    }
}
