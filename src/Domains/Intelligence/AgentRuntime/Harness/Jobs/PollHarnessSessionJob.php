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
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\AbsorbHarnessTickAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestSessionExtensionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\SessionCostService;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\SessionPostMortemService;
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
    public const int EXTENSION_WAIT_MINUTES = 30;
    /** How long an approved request may take to flip the session back before the resume is judged failed. */
    private const int RESUME_GRACE_MINUTES = 2;

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

        if ($session->harnessStatus() === HarnessStatusEnum::AWAITING_EXTENSION) {
            $this->awaitExtension($session);

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

        if ($this->stopForCost($session, $tick)) {
            return;
        }

        if ($tick->status === HarnessStatusEnum::IDLE || $tick->status->isTerminal()) {
            new FinalizeHarnessSessionAction($session, $tick)->execute();

            return;
        }

        if ($tick->status->isWaitingOnAHuman()) {
            $this->pollAgain($this->attempt);

            return;
        }

        $maxMinutes = new SessionCostService()->maxActiveMinutesFor($session);

        if ($this->attempt >= $this->maxAttempts($maxMinutes)) {
            $this->reachLimit($session, $tick, 'time limit of ' . $maxMinutes . ' minutes');

            return;
        }

        $this->pollAgain($this->attempt + 1);
    }

    private function maxAttempts(int $maxMinutes): int
    {
        return (int) ceil($maxMinutes * 60 / self::POLL_INTERVAL_SECONDS);
    }

    private function pollAgain(int $attempt): void
    {
        self::dispatch($this->app, $this->sessionId, $attempt)
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

    private function stopForCost(AgentTaskSession $session, HarnessTick $tick): bool
    {
        $cap = new SessionCostService()->capFor($session);

        if ($cap === null || (float) $session->estimated_cost < $cap) {
            return false;
        }

        $this->reachLimit($session, $tick, 'cost limit of $' . number_format($cap, 2));

        return true;
    }

    /**
     * Pauses for a human when the tenant has a `coding_extension` policy, stops otherwise — with the
     * post-mortem either way, so whoever reads it can tell a wedged run from one that was nearly done.
     */
    private function reachLimit(AgentTaskSession $session, HarnessTick $tick, string $limit): void
    {
        $postMortem = new SessionPostMortemService()->describe($session, $tick, $limit);

        if (new RequestSessionExtensionAction($session, $limit, $postMortem)->execute() !== null) {
            $this->pollAgain($this->attempt);

            return;
        }

        $this->fail($session, $postMortem['text'], HarnessStatusEnum::CANCELLED);
    }

    /**
     * Keeps ticking while parked, without touching the runtime: the approval decides, and this is what
     * notices the decision. The heartbeat is touched so the silence sweeper does not reap a session
     * that is quiet because it was told to be.
     */
    private function awaitExtension(AgentTaskSession $session): void
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('approval_type', RequestSessionExtensionAction::APPROVAL_TYPE)
            ->where('entity_id', $session->task_id)
            ->latest('id')
            ->first();

        $verdict = $this->extensionVerdict($request);

        if ($verdict === null) {
            $session->touchHeartbeat();
            $session->saveOrFail();
            $this->pollAgain($this->attempt);

            return;
        }

        [$outcome, $status] = $verdict;

        $this->fail(
            $session,
            trim((string) $session->error_message . "\n\n" . $outcome),
            $status,
            interrupt: false
        );
    }

    /**
     * Null while the decision is still coming; otherwise why the run ends and as what.
     *
     * @return array{0: string, 1: HarnessStatusEnum}|null
     */
    private function extensionVerdict(?ApprovalRequest $request): ?array
    {
        if ($request === null) {
            return ['The request for more time no longer exists.', HarnessStatusEnum::CANCELLED];
        }

        return match ($request->status) {
            ApprovalStatusEnum::PENDING => $request->created_at->gt(Carbon::now()->subMinutes(self::EXTENSION_WAIT_MINUTES))
                ? null
                : $this->withdraw($request),
            ApprovalStatusEnum::APPROVED => $request->resolved_at?->gt(Carbon::now()->subMinutes(self::RESUME_GRACE_MINUTES))
                ? null
                : ['More time was approved, but the session could not be resumed.', HarnessStatusEnum::FAILED],
            default => ['More time was not approved (' . $request->status->value . ').', HarnessStatusEnum::CANCELLED],
        };
    }

    /**
     * @return array{0: string, 1: HarnessStatusEnum}
     */
    private function withdraw(ApprovalRequest $request): array
    {
        $reason = 'Nobody approved more time within ' . self::EXTENSION_WAIT_MINUTES . ' minutes.';
        new CancelApprovalAction($request, $reason)->execute();

        return [$reason, HarnessStatusEnum::CANCELLED];
    }

    /**
     * Unreachable is not the same as finished. The container may be starting, or the machine may have
     * blipped, so a single failure re-queues; only sustained silence ends the run.
     */
    private function handleUnreachable(AgentTaskSession $session, Throwable $e): void
    {
        $session->error_message = $e->getMessage();
        $session->saveOrFail();

        if ($this->attempt >= $this->maxAttempts(new SessionCostService()->maxActiveMinutesFor($session))) {
            $this->fail(
                $session,
                'The coding session became unreachable: ' . $e->getMessage(),
                HarnessStatusEnum::FAILED,
                interrupt: false
            );

            return;
        }

        $this->pollAgain($this->attempt + 1);
    }

    /**
     * Marking the row terminal does nothing to the container: without the interrupt the model keeps
     * working, and spending, on a session nobody is watching any more. Skipped only when the harness is
     * already unreachable, where the call could only fail and report.
     */
    private function fail(
        AgentTaskSession $session,
        string $reason,
        HarnessStatusEnum $status,
        bool $interrupt = true
    ): void {
        if ($interrupt) {
            HarnessFactory::interrupt($session);
        }

        $session->status = $status->value;
        $session->error_message = $reason;
        $session->saveOrFail();

        new FinalizeHarnessSessionAction($session, null, $reason)->execute();
    }
}
