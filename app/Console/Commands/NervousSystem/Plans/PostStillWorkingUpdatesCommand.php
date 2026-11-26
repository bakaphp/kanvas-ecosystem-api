<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Plans;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Jobs\PostStillWorkingUpdateJob;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Pure dispatcher: it finds the chats with work running for them, in the apps that turned the updates on,
 * and queues one job per chat. It does no app-scoped work itself, so it does not call overwriteAppService;
 * the job rebinds to its own app.
 */
class PostStillWorkingUpdatesCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:post-still-working-updates';

    protected $description = 'Tell a chat that has been silent for three minutes that the work it asked for is still running.';

    public function handle(): int
    {
        $sessionIds = Plan::query()
            ->workingForAConversation()
            ->distinct()
            ->pluck('origin_session_id');

        $queued = 0;
        $enabled = [];

        foreach (Session::query()->with('app')->whereIn('id', $sessionIds)->get() as $session) {
            $app = $session->app;

            if (! $app instanceof Apps) {
                continue;
            }

            $enabled[$app->getId()] ??= $app->getBool(AgentRunConfigurationEnum::STILL_WORKING_UPDATES->value);

            if (! $enabled[$app->getId()]) {
                continue;
            }

            PostStillWorkingUpdateJob::dispatch($session);
            $queued++;
        }

        $this->info(sprintf('still-working: queued %d chat(s).', $queued));

        return self::SUCCESS;
    }
}
