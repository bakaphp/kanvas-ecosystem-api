<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Social\Tags\Models\TagEntity;

class ExportSocialDataToRecommendatorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:export-social-data';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Export social data to GCP Agent Builder';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $app = Apps::find(78);

        // Get User Messages records from app
        // UserMessage::fromApp($app)
        //     ->where('is_deleted', 0)
        //     ->chunk(100, function ($userMessages) {
        //         foreach ($userMessages as $message) {
        //             echo "hello";
        //             die();
        //         }
        //     });
        // Get user follows data from app

        // Get tags
        // Tag::where('apps_id', $app->getId())
        // ->where('is_deleted', 0)
        // ->chunk(100, function ($tags) {
        //     foreach ($tags as $tag) {
        //         // Process each message
        //         echo $tag->slug;
        //     }
        // });

        // Get tags_entities by app

        // Get messages by app and message_type_id
        UsersFollows::fromApp($app)
            ->where('is_deleted', 0)
            ->chunk(100, function ($follows) {
                foreach ($follows as $follow) {
                    echo $follow->users_id;
                    die();
                }
            });

    }
}
