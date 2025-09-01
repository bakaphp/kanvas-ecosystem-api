<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Companies\Models\Companies;

class ChangeMediaUrlCommand extends Command
{
    private const OLD_MEDIA_URL = 'https://s3.amazonaws.com/mc-canvas/';
    private const CDN_URL = 'https://cdn.promptmine.ai/';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:change-media-url {--appId=78} {--messageType=588} {--companyId=2626}';

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

        $appId = (int) $this->option('appId');
        $messageType = (int) $this->option('messageType');
        $companyId = (int) $this->option('companyId');

        $app = Apps::find($appId);
        $messageType = MessageType::fromApp($app)->where('id', $messageType)->firstOrFail();
        $company = Companies::where('id', $companyId)->where('is_deleted', 0)->firstOrFail();
        $this->refactorMediaUrl($app, $messageType, $company);

    }

    private function refactorMediaUrl(Apps $app, MessageType $messageType, Companies $company): void
    {
        Message::fromApp($app)
            ->where('companies_id', $company->getId())
            ->where('messages_types_id', $messageType->getKey())
            ->chunk(100, function ($messages) use ($app){
                foreach ($messages as $message) {
                    DB::beginTransaction();
                    try {
                        Log::info(sprintf('Updating Message ID %d: url', $message->getId()));

                        $this->toCdnUrl($message);
                        DB::commit();
                        Log::info(sprintf('Successfully updated Message ID %d: url', $message->getId()));
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error(sprintf('Failed to update Message ID %d: %s', $message->getId(), $e->getMessage()));
                    }
                }
            });
    }

    private function toCdnUrl(Message $message): void
    {
        $messageData = $message->message;

        match ($messageData['type']) {
            'video-format' => $messageData['video'] = str_replace(self::OLD_MEDIA_URL, self::CDN_URL, $messageData['video']),
            'image-format' => $messageData['image'] = str_replace(self::OLD_MEDIA_URL, self::CDN_URL, $messageData['image']),
        };

        $message->message = $messageData;

        $message->saveOrFail();
    }
}
