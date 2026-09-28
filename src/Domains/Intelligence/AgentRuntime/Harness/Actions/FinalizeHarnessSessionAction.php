<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\OpenCode\Services\CodingPolicy;
use Kanvas\Intelligence\AgentRuntime\Harness\Concerns\PostsSessionActivity;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Throwable;

/**
 * Closes a session: collect the diff, take the handoff out of the container, mirror the outcome onto
 * the Task and the Plan, and tell whoever asked.
 *
 * Runs exactly once per session — the poller stops as soon as this is called.
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
    ) {
    }

    public function execute(): AgentTaskSession
    {
        $status = $this->resolveStatus();
        $diff = $this->collectDiff();

        if ($status === HarnessStatusEnum::COMPLETED) {
            $this->captureHandoff();
        }

        // Read AFTER the handoff, because the handoff is usually where the claim lives.
        $status = $this->demoteEmptyRun($status, $diff);

        $this->session->status = $status->value;
        $this->session->completed_at = Carbon::now();
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        $this->mirrorOntoTask($status, $diff);
        $approval = $this->requestPushApproval($status, $diff);
        // No policy configured means no one asked to be consulted, so the branch goes up the way an
        // engineer's would. `requestPushApproval` returns null in exactly that case.
        $push = $approval === null ? $this->pushBranch($status, $diff) : null;
        $this->announce($status, $diff, $approval !== null, $push);

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
     * is not: it is trusted, built on, and found out much later.
     */
    private function demoteEmptyRun(HarnessStatusEnum $status, HarnessDiff $diff): HarnessStatusEnum
    {
        if ($status !== HarnessStatusEnum::COMPLETED || ! $diff->isEmpty() || $this->session->branch === null) {
            return $status;
        }

        $this->session->error_message = 'The agent reported its work as done, but no file in the '
            . 'workspace changed. Nothing was committed or pushed. This is usually the model answering '
            . 'in prose instead of calling its editing tools — re-run it, and say only what to change.';

        return HarnessStatusEnum::FAILED;
    }

    /**
     * Pushes the branch when nothing is waiting to be asked.
     *
     * @return array{pushed: bool, branch?: string, files?: int, error?: string, pull_request?: string, pull_request_error?: string}|null
     */
    private function pushBranch(HarnessStatusEnum $status, HarnessDiff $diff): ?array
    {
        if ($status !== HarnessStatusEnum::COMPLETED || $this->session->branch === null || $diff->isEmpty()) {
            return null;
        }

        return new PushSessionAction($this->session)->execute();
    }

    /**
     * What a person needs to know: where the work is, or why it is not where they expected.
     *
     * @param array{pushed: bool, branch?: string, files?: int, error?: string, pull_request?: string, pull_request_error?: string}|null $push
     */
    private function outcomeLine(bool $awaitingApproval, ?array $push): string
    {
        if ($awaitingApproval) {
            return 'Waiting on your approval to push `' . (string) $this->session->branch . '`.';
        }

        if ($push === null) {
            return $this->reviewLine();
        }

        if (($push['pushed'] ?? false) === true) {
            $branch = 'Pushed to `' . (string) ($push['branch'] ?? $this->session->branch) . '`.';

            if (isset($push['pull_request'])) {
                return $branch . ' Pull request open for review: ' . (string) $push['pull_request'];
            }

            return isset($push['pull_request_error'])
                ? $branch . ' No pull request was opened (' . (string) $push['pull_request_error'] . ').'
                : $branch;
        }

        // Said out loud, because the work exists and everyone will assume it is on the branch.
        return 'The work is done but the push FAILED: ' . (string) ($push['error'] ?? 'unknown reason')
            . "\nIt is still in the workspace on branch `" . (string) $this->session->branch . '`.';
    }

    private function resolveStatus(): HarnessStatusEnum
    {
        if ($this->failureReason !== null) {
            $current = $this->session->harnessStatus();

            return $current->isTerminal() ? $current : HarnessStatusEnum::FAILED;
        }

        return match ($this->tick?->status) {
            HarnessStatusEnum::IDLE, HarnessStatusEnum::COMPLETED => HarnessStatusEnum::COMPLETED,
            HarnessStatusEnum::CANCELLED => HarnessStatusEnum::CANCELLED,
            default => HarnessStatusEnum::FAILED,
        };
    }

    private function collectDiff(): HarnessDiff
    {
        try {
            $harness = HarnessFactory::forSession($this->session);

            return $harness instanceof CodingHarness ? $harness->diff($this->session) : new HarnessDiff();
        } catch (Throwable $e) {
            report($e);

            return new HarnessDiff();
        }
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

    private function mirrorOntoTask(HarnessStatusEnum $status, HarnessDiff $diff): void
    {
        /** @var Task|null $task */
        $task = $this->session->task;

        if ($task === null) {
            return;
        }

        $taskStatus = match ($status) {
            HarnessStatusEnum::COMPLETED => TaskStatusEnum::DONE,
            HarnessStatusEnum::CANCELLED => TaskStatusEnum::SKIPPED,
            default => TaskStatusEnum::BLOCKED,
        };

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: $taskStatus,
            result: $taskStatus === TaskStatusEnum::DONE ? [
                'summary' => $diff->summary(),
                'files' => $diff->paths(),
                'branch' => $this->session->branch,
                'handoff' => $this->session->handoff,
            ] : null,
            blockedReason: $taskStatus === TaskStatusEnum::BLOCKED
                ? ($this->failureReason ?? $this->session->error_message ?? 'The coding session failed.')
                : null,
            fromSync: true,
        )->execute();

        $this->closePlan($task, $status);
    }

    /**
     * `UpdateTaskStatusAction` moves the task and rolls up the percentage but never the plan's own
     * status, and `saveQuietly` fires no model events — so without the explicit broadcast the board
     * shows a finished task under a plan still marked active until someone refreshes.
     */
    private function closePlan(Task $task, HarnessStatusEnum $status): void
    {
        /** @var Plan|null $plan */
        $plan = $task->plan;

        if ($plan === null) {
            return;
        }

        $previousStatus = $plan->status;
        $plan->status = match ($status) {
            HarnessStatusEnum::COMPLETED => PlanStatusEnum::DONE->value,
            HarnessStatusEnum::CANCELLED => PlanStatusEnum::CANCELLED->value,
            default => PlanStatusEnum::FAILED->value,
        };

        if ($status === HarnessStatusEnum::COMPLETED) {
            $plan->completed_at = Carbon::now();
        }

        $plan->saveQuietly();

        try {
            $plan->broadcastChange(PlanChangeTypeEnum::UPDATED, previousStatus: $previousStatus);
        } catch (Throwable $e) {
            report($e);
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
    /**
     * A finished job with a branch and a diff is not done — it is waiting on a person. Asking here,
     * rather than leaving it to whoever notices, is what makes the push gate real.
     */
    private function requestPushApproval(HarnessStatusEnum $status, HarnessDiff $diff): ?ApprovalRequest
    {
        if ($status !== HarnessStatusEnum::COMPLETED) {
            return null;
        }

        try {
            return new RequestPushApprovalAction($this->session, $diff)->execute();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param array{pushed: bool, branch?: string, files?: int, error?: string, pull_request?: string, pull_request_error?: string}|null $push
     */
    private function announce(
        HarnessStatusEnum $status,
        HarnessDiff $diff,
        bool $awaitingApproval = false,
        ?array $push = null
    ): void {
        /** @var Plan|null $plan */
        $plan = $this->session->plan;

        if ($plan === null) {
            return;
        }

        $title = match ($status) {
            HarnessStatusEnum::COMPLETED => '✅ Coding job finished',
            HarnessStatusEnum::CANCELLED => '🛑 Coding job stopped',
            default => '⚠️ Coding job failed',
        };

        $detail = match ($status) {
            HarnessStatusEnum::COMPLETED => 'Changes: ' . $diff->summary()
                . ($diff->isEmpty() ? '' : "\n" . implode("\n", array_map(
                    static fn (string $path): string => '· ' . $path,
                    $diff->paths()
                )))
                . "\n\n" . $this->outcomeLine($awaitingApproval, $push),
            default => $this->failureReason ?? $this->session->error_message ?? 'No reason reported.',
        };

        $body = $title . "\n\n" . $detail . "\n\n" . $this->costLine();

        $this->postToPlanBoard($plan, $body, 'coding_job_result', 'coding-job-alert');
        $this->alsoPostToOriginConversation($plan, $body, 'coding_job_result');
        $this->notifyTheAsker($plan, $title, $body);
    }

    private function reviewLine(): string
    {
        return $this->session->branch === null
            ? 'Nothing has been pushed — the diff is waiting on the workspace for review.'
            : 'Nothing has been pushed — the work is on branch `' . $this->session->branch . '` for review.';
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
