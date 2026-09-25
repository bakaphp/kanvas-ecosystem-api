<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

/**
 * Ends sessions that stopped talking.
 *
 * Silence is the only failure signal a harness gives reliably — a wedged provider call produces no
 * error event, no message and nothing the API will admit to — so a session with no activity is treated
 * as dead rather than patiently waited on. Without this, a stuck run holds a tenant's concurrency slot
 * and a container forever.
 */
class SweepCodingSessionsCommand extends Command
{
    use KanvasJobsTrait;

    private const int STALE_MINUTES = 10;
    private const int MAX_SESSION_MINUTES = 45;

    protected $signature = 'kanvas:coding:sweep-sessions
        {--dry-run : List what would be swept without touching anything}';

    protected $description = 'Interrupt and close coding sessions that have gone quiet or overrun.';

    public function handle(): int
    {
        $staleBefore = Carbon::now()->subMinutes(self::STALE_MINUTES);
        $startedBefore = Carbon::now()->subMinutes(self::MAX_SESSION_MINUTES);
        $dryRun = (bool) $this->option('dry-run');

        $sessions = AgentTaskSession::query()
            ->notDeleted()
            ->live()
            ->where(function ($query) use ($staleBefore, $startedBefore): void {
                $query->where('heartbeat_at', '<', $staleBefore)
                    ->orWhere('started_at', '<', $startedBefore)
                    // A row that died before it was ever provisioned has NULL timestamps, and
                    // `NULL < x` is NULL in SQL — without this it is invisible to the sweeper forever
                    // while still counting against the tenant's concurrency limit.
                    ->orWhere(function ($stillborn) use ($staleBefore): void {
                        $stillborn->whereNull('heartbeat_at')->where('created_at', '<', $staleBefore);
                    });
            })
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        foreach ($sessions as $session) {
            $reason = match (true) {
                $session->heartbeat_at === null => 'The session never started — it was cleaned up.',
                $session->heartbeat_at->lt($staleBefore) => 'No activity for ' . self::STALE_MINUTES
                    . ' minutes — the session was interrupted.',
                default => 'The session ran past ' . self::MAX_SESSION_MINUTES
                    . ' minutes and was interrupted.',
            };

            $this->line(($dryRun ? '[dry-run] ' : '') . $session->uuid . ' · ' . $session->status . ' · ' . $reason);

            if ($dryRun) {
                continue;
            }

            $this->sweep($session, $reason);
        }

        return self::SUCCESS;
    }

    private function sweep(AgentTaskSession $session, string $reason): void
    {
        /** @var Apps|null $app */
        $app = $session->app;

        if ($app === null) {
            return;
        }

        // Per session, not once for the run: Bouncer scope and the container-bound app leak between
        // tenants otherwise, and this loop legitimately crosses apps.
        $this->overwriteAppService($app);

        try {
            HarnessFactory::forSession($session)->stop($session);
        } catch (Throwable $e) {
            // Unreachable is the expected case here — the whole reason it is being swept.
            report($e);
        }

        $session->status = HarnessStatusEnum::CANCELLED->value;
        $session->error_message = $reason;
        $session->saveOrFail();

        try {
            new FinalizeHarnessSessionAction($session, null, $reason)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
