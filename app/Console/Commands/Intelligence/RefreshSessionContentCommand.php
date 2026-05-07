<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;

class RefreshSessionContentCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:refresh-session-content {apps_id} {sessionId}';

    protected $description = 'Refresh the content of a session by its ID';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $sessionId = $this->argument('sessionId');
        $session = Session::getById($sessionId, $app);

        if (! $session) {
            $this->error("Session with ID {$sessionId} not found.");

            return Command::FAILURE;
        }
        $content = new CreateContentSessionAction($session)->execute();
        $session->content = $content;
        $session->saveOrFail();

        $this->info("Session content refreshed successfully for session ID {$sessionId}.");

        return Command::SUCCESS;
    }
}
