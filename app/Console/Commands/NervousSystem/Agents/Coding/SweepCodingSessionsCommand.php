<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

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
 *
 * Deliberately no wall-clock ceiling: the poller owns the time limit, which is per tenant, excludes
 * time parked on a human, and can be extended by approval. A fixed ceiling here would override all three.
 */
class SweepCodingSessionsCommand extends Command
{
    use KanvasJobsTrait;

    private const int STALE_MINUTES = 10;

    protected $signature = 'kanvas:coding:sweep-sessions
        {--dry-run : List what would be swept without touching anything}';

    protected $description = 'Interrupt and close coding sessions that have gone quiet.';

    public function handle(): int
    {
        $staleBefore = Carbon::now()->subMinutes(self::STALE_MINUTES);
        $dryRun = (bool) $this->option('dry-run');

        $sessions = AgentTaskSession::query()
            ->notDeleted()
            ->live()
            ->where(function ($query) use ($staleBefore): void {
                $query->where('heartbeat_at', '<', $staleBefore)
                    // A row that died before it was ever provisioned has NULL timestamps, and
                    // `NULL < x` is NULL in SQL — without this it is invisible to the sweeper forever
                    // while still counting against the tenant's concurrency limit.
                    ->orWhere(function ($stillborn) use ($staleBefore): void {
                        $stillborn->whereNull('heartbeat_at')->where('created_at', '<', $staleBefore);
                    });
            })
            ->get()
            ->filter(fn (AgentTaskSession $session): bool => $this->isStale($session));

        if ($sessions->isEmpty()) {
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        foreach ($sessions as $session) {
            $reason = $this->reasonFor($session);

            $this->line(($dryRun ? '[dry-run] ' : '') . $session->uuid . ' · ' . $session->status . ' · ' . $reason);

            if ($dryRun) {
                continue;
            }

            $this->sweep($session, $reason);
        }

        return self::SUCCESS;
    }

    /**
     * A row that never reached a machine is a queue problem, not a hung runtime, and the PM acts on
     * the difference: it cancels and re-dispatches a "hung" job, which only restarts the wait.
     */
    private function reasonFor(AgentTaskSession $session): string
    {
        $stage = $session->launchStage();

        if ($stage !== null && ! $session->isProvisioned()) {
            return 'The launch stalled while ' . $stage->label() . ' — no progress for '
                . $stage->staleMinutes(self::STALE_MINUTES) . ' minutes.';
        }

        if (! $session->isProvisioned()) {
            return 'The launch never started within ' . self::STALE_MINUTES
                . ' minutes — the coding queue did not pick it up; nothing ran on a machine.';
        }

        return 'No activity for ' . self::STALE_MINUTES . ' minutes — the session was interrupted.';
    }

    /**
     * The query uses the default window; a launch stage can only lengthen it (a mirror clone is
     * silent for up to 15 minutes by design), so the stage is checked here rather than in SQL.
     */
    private function isStale(AgentTaskSession $session): bool
    {
        $minutes = $session->launchStage()?->staleMinutes(self::STALE_MINUTES) ?? self::STALE_MINUTES;
        $lastSeen = $session->heartbeat_at ?? $session->created_at;

        return $lastSeen === null || $lastSeen->lt(Carbon::now()->subMinutes($minutes));
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

        HarnessFactory::interrupt($session);

        $session->status = HarnessStatusEnum::CANCELLED->value;
        $session->error_message = $reason;
        $session->saveOrFail();

        try {
            new FinalizeHarnessSessionAction($session, failureReason: $reason, silence: true)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
