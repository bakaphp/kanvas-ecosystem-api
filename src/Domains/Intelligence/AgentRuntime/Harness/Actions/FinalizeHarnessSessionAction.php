<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\OpenCode\Services\CodingPolicy;
use Kanvas\Intelligence\AgentRuntime\Harness\Concerns\PostsSessionActivity;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\CodingOutcome;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\VerifyHarnessDiffJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Closes a session: collect the diff, take the handoff out of the container, record the outcome on
 * the Task and the Plan, and tell whoever asked.
 *
 * Runs once per session — the poller stops as soon as this is called — with one exception: a
 * completed run whose diff could not be read parks as `verifying_diff` and comes back through here
 * with the diff once `VerifyHarnessDiffJob` has it, or with a failure reason once it gives up.
 *
 * **It does not stop the container.** A finished job is the moment someone wants to look at the diff,
 * ask a follow-up, or approve the push — and all three need the runtime that was just removed. Killing
 * it here made "show me what changed" fail with "No such container" seconds after the work landed.
 * `ReapOrphanCodingContainersAction` retires it once the agent has been idle and nothing is waiting on
 * it, which is a decision about the machine rather than about this session.
 */
class FinalizeHarnessSessionAction
{
    use AnnouncesPlanOutcome;
    use PostsSessionActivity;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly ?HarnessTick $tick = null,
        private readonly ?string $failureReason = null,
        /** The diff already in hand, when the verification job is resuming a parked run. */
        private readonly ?HarnessDiff $diff = null,
        /** The runtime went quiet and was given up on: not a verdict on the work, so it may be retried. */
        private readonly bool $silence = false,
    ) {
    }

    public function execute(): AgentTaskSession
    {
        $status = $this->resolveStatus();
        $resuming = $this->session->harnessStatus() === HarnessStatusEnum::VERIFYING_DIFF;

        if ($status === HarnessStatusEnum::COMPLETED && ! $resuming) {
            $this->captureHandoff();
        }

        // Read AFTER the handoff, because the handoff is usually where the claim lives.
        $diff = $this->diff ?? HarnessFactory::diffOrNull($this->session);

        // "Could not read the diff" is not "no changes". Recording it as empty turned a workspace
        // hiccup into a failed run with a post-mortem a person then chased.
        if ($status === HarnessStatusEnum::COMPLETED && $diff === null) {
            $this->parkForDiffVerification();

            return $this->session;
        }

        $diff ??= new HarnessDiff();
        $emptyRun = $status === HarnessStatusEnum::COMPLETED && $diff->isEmpty();

        if ($emptyRun) {
            $status = $this->failEmptyRun();
        }

        $this->session->status = $status->value;
        $this->session->completed_at = Carbon::now();
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        if ($this->retryable($emptyRun, $diff) && $this->retry()) {
            return $this->session;
        }

        $outcome = $this->outcome($status, $diff);

        new RecordCodingOutcomeAction($this->session, $outcome)->execute();
        $this->announce($status, $diff, $outcome);

        return $this->session;
    }

    /**
     * A coding job that changed no files did not succeed, whatever it says about itself.
     *
     * The deliverable here is a diff. A model that never called a tool still answers fluently — "the
     * file has been created at the repository root" — and the handoff says `done`, so the run looked
     * identical to a real one: completed, costed, narrated. The only thing that noticed was a person
     * opening GitHub and finding nothing there.
     *
     * Reporting it as failed is the safe direction. A genuine no-op task — investigate this, confirm
     * that — gets marked failed and a human sees why in one line, which is cheap. A fabricated success
     * is not: it is trusted, built on, and found out much later. Branchless runs are held to the same
     * rule.
     */
    private function failEmptyRun(): HarnessStatusEnum
    {
        $this->session->error_message = 'The agent reported its work as done, but no file in the '
            . 'workspace changed. Nothing was committed or pushed. This is usually the model answering '
            . 'in prose instead of calling its editing tools — re-run it, and say only what to change.';

        return HarnessStatusEnum::FAILED;
    }

    /**
     * One more try when the run produced nothing at all: a model that answered in prose instead of
     * editing, or a runtime that never spoke. Once — the second empty run blocks the task like any
     * other failure. Never for a run that made edits, that a person stopped, or whose diff merely
     * could not be read: those are verdicts, not blanks.
     */
    private function retryable(bool $emptyRun, HarnessDiff $diff): bool
    {
        if ($this->session->isRetry() || ! $diff->isEmpty()) {
            return false;
        }

        return $emptyRun || ($this->silence && $this->session->usage()->totalTokens() === 0);
    }

    /**
     * A retry that cannot start (no capacity, no machine) is not a reason to lose the first run's
     * ending: it falls through to the ordinary failure, which a person then sees.
     */
    private function retry(): bool
    {
        try {
            new RetryHarnessSessionAction($this->session)->execute();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function outcome(HarnessStatusEnum $status, HarnessDiff $diff): CodingOutcome
    {
        $reason = $this->failureReason ?? $this->session->error_message ?? 'No reason reported.';

        return match ($status) {
            HarnessStatusEnum::COMPLETED => $this->completedOutcome($diff),
            HarnessStatusEnum::CANCELLED => CodingOutcome::cancelled($diff, $reason),
            default => CodingOutcome::failed($diff, $reason),
        };
    }

    /**
     * No policy configured means no one asked to be consulted, so the branch goes up the way an
     * engineer's would. A branchless run (attach mode) has nothing to push and is reviewed in place.
     */
    private function completedOutcome(HarnessDiff $diff): CodingOutcome
    {
        $approval = $this->requestPushApproval($diff);

        if ($approval !== null) {
            return CodingOutcome::awaitingPush($diff, $approval, $this->session->branch);
        }

        if ($this->session->branch === null) {
            return CodingOutcome::reviewOnly($diff);
        }

        return CodingOutcome::fromPush(new PushSessionAction($this->session)->execute(), $diff, $this->session->branch);
    }

    private function parkForDiffVerification(): void
    {
        $this->session->status = HarnessStatusEnum::VERIFYING_DIFF->value;
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        $this->postToPlan(
            $this->session,
            'The agent reported done, but the workspace has not confirmed what changed yet. Checking again '
            . 'for a few minutes before recording the result.',
            'coding_job_verifying_diff',
        );

        VerifyHarnessDiffJob::dispatch($this->session->app, $this->session->getId())
            ->delay(Carbon::now()->addSeconds(VerifyHarnessDiffJob::INTERVAL_SECONDS));
    }

    private function resolveStatus(): HarnessStatusEnum
    {
        if ($this->failureReason !== null) {
            $current = $this->session->harnessStatus();

            return $current->isTerminal() ? $current : HarnessStatusEnum::FAILED;
        }

        if ($this->session->harnessStatus() === HarnessStatusEnum::VERIFYING_DIFF && $this->diff !== null) {
            return HarnessStatusEnum::COMPLETED;
        }

        return match ($this->tick?->status) {
            HarnessStatusEnum::IDLE, HarnessStatusEnum::COMPLETED => HarnessStatusEnum::COMPLETED,
            HarnessStatusEnum::CANCELLED => HarnessStatusEnum::CANCELLED,
            default => HarnessStatusEnum::FAILED,
        };
    }

    /**
     * Asked while the context is still live and stored outside the container, so the next session on
     * this repository starts knowing what this one did — the difference between a coding tool and a
     * coding agent.
     */
    private function captureHandoff(): void
    {
        try {
            $harness = HarnessFactory::forSession($this->session);
            $harness->ask($this->session, CodingPolicy::HANDOFF_REQUEST);

            // One short read rather than another poll cycle: the answer is a single small message and
            // the session is about to be torn down.
            sleep(5);
            $tick = $harness->poll($this->session);
            $handoff = trim(implode("\n", $tick->narration));

            if ($handoff !== '') {
                $this->session->handoff = $handoff;
                $this->session->saveOrFail();

                // Distilled now, while the handoff is in hand: the session row ages out, a memory
                // about the repository should not.
                new ExtractSessionMemoriesAction($this->session)->execute();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A finished job with a branch and a diff is not done — it is waiting on a person. Asking here,
     * rather than leaving it to whoever notices, is what makes the push gate real.
     */
    private function requestPushApproval(HarnessDiff $diff): ?ApprovalRequest
    {
        try {
            return new RequestPushApprovalAction($this->session, $diff)->execute();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Three surfaces, because the board alone reaches nobody: the plan's own record, the conversation
     * where the work was asked for, and a direct notification to the asker.
     *
     * The hand-rolled version of this notified `$plan->user`, which for an autonomous run is the
     * AGENT's user — so the person who asked heard nothing at all. `AnnouncesPlanOutcome` resolves
     * `origin_users_id` instead, and is shared with the other pollers so the fix cannot drift again.
     */
    private function announce(HarnessStatusEnum $status, HarnessDiff $diff, CodingOutcome $outcome): void
    {
        /** @var Plan|null $plan */
        $plan = $this->session->plan;

        if ($plan === null) {
            return;
        }

        $title = $outcome->kind->headline();

        $detail = match ($status) {
            HarnessStatusEnum::COMPLETED => 'Changes: ' . $diff->summary()
                . ($diff->isEmpty() ? '' : "\n" . implode("\n", array_map(
                    static fn (string $path): string => '· ' . $path,
                    $diff->paths()
                )))
                . "\n\n" . $outcome->line(),
            default => $outcome->line(),
        };

        $body = $title . "\n\n" . $detail . "\n\n" . $this->costLine();

        $this->postToPlanBoard(
            $plan,
            $body,
            'coding_job_result',
            'coding-job-alert',
        );
        $this->alsoPostToOriginConversation($plan, $body, 'coding_job_result');
        $this->notifyTheAsker($plan, $title, $body);
    }

    private function costLine(): string
    {
        $usage = $this->session->usage();

        return sprintf(
            '%s · %ds · %s in / %s out · ~$%s',
            $this->session->model ?? 'unknown model',
            $this->session->elapsedSeconds(),
            number_format($usage->inputTokens),
            number_format($usage->outputTokens),
            number_format((float) $this->session->estimated_cost, 4)
        );
    }
}
