<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Services\RecombeeInteractionService;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Throwable;

class IndexUserInteractionRecombeeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:prompt-index-recombee-user-interactions {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Index prompt to recombee';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $query = UsersInteractions::fromApp($app);
        $cursor = $query->cursor();
        $totalMessages = $query->count();

        $this->output->progressStart($totalMessages);
        $recommendationService = new RecombeeInteractionService($app);

        foreach ($cursor as $userInteraction) {
            try {
                $recommendationService->addUserInteraction($userInteraction);
                $this->output->progressAdvance();
            } catch (Throwable $e) {
                $this->output->error($e->getMessage());
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

    }
}
