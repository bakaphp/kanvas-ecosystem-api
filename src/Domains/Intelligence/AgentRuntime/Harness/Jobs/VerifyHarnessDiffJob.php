<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * Re-reads the diff of a run whose workspace did not answer when the agent reported done.
 *
 * The alternative — recording an unreadable diff as empty — turned a transient hiccup into "the
 * agent changed nothing", a failure a person then investigates. Ten minutes of asking again costs
 * nothing; past that the run is failed with the real reason, and still never marked delivered.
 */
class VerifyHarnessDiffJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int INTERVAL_SECONDS = 60;
    public const int MAX_ATTEMPTS = 10;

    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->fromApp($this->app)->first();

        if ($session === null || $session->harnessStatus() !== HarnessStatusEnum::VERIFYING_DIFF) {
            return;
        }

        $diff = HarnessFactory::diffOrNull($session);

        if ($diff !== null) {
            new FinalizeHarnessSessionAction($session, diff: $diff)->execute();

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            new FinalizeHarnessSessionAction($session, failureReason: sprintf(
                'The agent reported its work as done, but the workspace could not say what changed for %d '
                . 'minutes afterwards. Nothing has been recorded as delivered or pushed.',
                (int) (self::MAX_ATTEMPTS * self::INTERVAL_SECONDS / 60),
            ))->execute();

            return;
        }

        // The sweep reaps live sessions on a stale heartbeat; this one is alive and waiting on purpose.
        $session->touchHeartbeat();
        $session->saveOrFail();

        self::dispatch($this->app, $this->sessionId, $this->attempt + 1)
            ->delay(Carbon::now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
