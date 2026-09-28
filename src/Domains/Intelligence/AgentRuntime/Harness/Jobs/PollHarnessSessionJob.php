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
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\AbsorbHarnessTickAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\SessionCostService;
use Throwable;

/**
 * Follows one session to a terminal state.
 *
 * Re-dispatches itself rather than looping, so a wedged harness cannot hold a worker. Sixty seconds
 * between ticks: nothing is lost in the gap because the cursor is the harness's own durable event
 * sequence, and in SSH mode every tick costs a handshake.
 */
class PollHarnessSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int POLL_INTERVAL_SECONDS = 60;
    public const int MAX_ATTEMPTS = 35;

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

        if ($session === null || ! $session->isLive()) {
            return;
        }

        try {
            $tick = HarnessFactory::forSession($session)->poll($session);
        } catch (Throwable $e) {
            $this->handleUnreachable($session, $e);

            return;
        }

        new AbsorbHarnessTickAction($session, $tick)->execute();

        if ($this->stopForSubstitutedModel($session, $tick)) {
            return;
        }

        if ($this->stopForCost($session)) {
            return;
        }

        if ($tick->status === HarnessStatusEnum::IDLE || $tick->status->isTerminal()) {
            new FinalizeHarnessSessionAction($session, $tick)->execute();

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $this->fail(
                $session,
                'The coding session ran past its time limit and was stopped.',
                HarnessStatusEnum::CANCELLED
            );

            return;
        }

        self::dispatch($this->app, $this->sessionId, $this->attempt + 1)
            ->delay(Carbon::now()->addSeconds(self::POLL_INTERVAL_SECONDS));
    }

    /**
     * A runtime that cannot resolve its configured provider does not fail — it quietly answers with a
     * hosted model of its own choosing, which means tenant code going somewhere nobody approved. The
     * pinned model is therefore checked on every tick, and a mismatch stops the run.
     */
    private function stopForSubstitutedModel(AgentTaskSession $session, HarnessTick $tick): bool
    {
        if ($session->model === null) {
            return false;
        }

        $substitute = $tick->substitutedModel([$session->model]);

        if ($substitute === null) {
            return false;
        }

        $this->fail(
            $session,
            'Stopped: the harness answered with "' . $substitute . '" instead of the pinned model "'
            . $session->model . '". Nothing was sent to the intended provider.',
            HarnessStatusEnum::FAILED
        );

        return true;
    }

    private function stopForCost(AgentTaskSession $session): bool
    {
        $cap = new SessionCostService()->capFor($session);

        if ($cap === null || (float) $session->estimated_cost < $cap) {
            return false;
        }

        $this->fail(
            $session,
            'Stopped: this session reached its cost limit of $' . number_format($cap, 2) . '.',
            HarnessStatusEnum::CANCELLED
        );

        return true;
    }

    /**
     * Unreachable is not the same as finished. The container may be starting, or the machine may have
     * blipped, so a single failure re-queues; only sustained silence ends the run.
     */
    private function handleUnreachable(AgentTaskSession $session, Throwable $e): void
    {
        $session->error_message = $e->getMessage();
        $session->saveOrFail();

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $this->fail($session, 'The coding session became unreachable: ' . $e->getMessage(), HarnessStatusEnum::FAILED);

            return;
        }

        self::dispatch($this->app, $this->sessionId, $this->attempt + 1)
            ->delay(Carbon::now()->addSeconds(self::POLL_INTERVAL_SECONDS));
    }

    private function fail(AgentTaskSession $session, string $reason, HarnessStatusEnum $status): void
    {
        $session->status = $status->value;
        $session->error_message = $reason;
        $session->saveOrFail();

        new FinalizeHarnessSessionAction($session, null, $reason)->execute();
    }
}
