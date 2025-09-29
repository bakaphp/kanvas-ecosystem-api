<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;

class RefreshSessionContentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:refresh-session-content {sessionId}';

    protected $description = 'Refresh the content of a session by its ID';

    public function handle(): int
    {
        $sessionId = $this->argument('sessionId');
        $session = Session::getById($sessionId);
        if (! $session) {
            $this->error("Session with ID {$sessionId} not found.");

            return Command::FAILURE;
        }
        $content = (new CreateContentSessionAction($session))->execute();
        $session->content = $content;
        $session->save();

        $this->info("Session content refreshed successfully for session ID {$sessionId}.");

        return Command::SUCCESS;
    }
}
