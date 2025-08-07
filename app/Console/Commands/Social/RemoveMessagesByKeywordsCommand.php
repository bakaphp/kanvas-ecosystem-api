<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class RemoveMessagesByKeywordsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:remove-messages-by-keywords {app_id} {message_type_id} {keywords*}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Remove messages by keywords';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $keywords = $this->argument('keywords');
        if (empty($keywords)) {
            $this->warn('No keywords provided. Exiting.');
            return;
        }
        //Get all messages from app, company and messase type
        $app_id = Apps::getById($this->argument('app_id'));
        $message_type_id = MessageType::getById($this->argument('message_type_id'));

        Message::query()
            ->where('apps_id', $app_id->getId())
            ->where('message_types_id', $message_type_id->getId())
            ->where('is_deleted', 0)
            ->where(function ($query) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $query->orWhere('message', 'like', '%' . $keyword . '%');
                }
            })
            ->chunk(50, function ($messages) {
                foreach ($messages as $message) {
                    echo('-Soft Deleting message: ' . $message->getId() . "-slug-" . $message->slug . PHP_EOL);
                    $message->is_deleted = 1;
                    $message->is_public = 0;
                    $message->save();
                    $message->unsearchableSync();
                }
            });
    }
}
