<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;

class ScoutMessageReindexCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-social:scout-message-reindex {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Reindex social messages by app';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->reindex($app);

        return;
    }

    public function reindex(Apps $app)
    {
        $this->info('Reindex scout index for message App ' . $app->name);
        $messages = Message::fromApp($app)->where('is_public', 1)->where('is_deleted', 0);

        $this->info('Total messages to reindexed: ' . $messages->count());
        $messages->searchable();
    }
}
