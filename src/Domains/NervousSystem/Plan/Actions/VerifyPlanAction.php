<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\VerifierToolPolicy;
use Throwable;

/**
 * Establishes that the goal was met — not that the boxes were ticked.
 *
 * Until now a plan reached DONE because the agent working it said so, which is marking your own
 * homework and is how these systems produce confident, complete-looking failures: every task closed,
 * the objective not achieved.
 *
 * Three things make this a check rather than a second opinion:
 *  - it runs under `VerifierToolPolicy`, so the verifier can read and cannot fix;
 *  - it runs with NO session at all, so it carries no history: not the worker's reasoning, not the
 *    plan's own thread, and not its own previous verification attempts. It sees the objective and
 *    what the tasks claim, and nothing else;
 *  - it is prompted to REFUTE, and an unclear answer counts as not verified.
 *
 * A failed verification blocks for a human rather than reopening the plan. An agent that failed
 * verification and then retries unsupervised is the loop this whole programme exists to prevent.
 */
class VerifyPlanAction
{
    /** What each task reported, as fed to the verifier. Trimmed at both ends — see the map below. */
    private const int REPORTED_CHAR_CAP = 400;

    /** Answering with this exact token is the only thing that counts as a pass. */
    public const string PASS_TOKEN = 'VERIFIED';

    public function __construct(
        private readonly Plan $plan,
    ) {
    }

    public function execute(): bool
    {
        $agent = $this->plan->agent;
        $owner = $this->plan->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return false;
        }

        $startedAt = microtime(true);

        try {
            $verdict = VerifierToolPolicy::within(fn (): string => new AgentChatKernel(
                agent: $agent,
                session: null,
                message: $this->prompt(),
                user: $owner,
                persistConversation: false,
                fallbackOnFailure: false,
            )->execute());
        } catch (Throwable $e) {
            // A verifier that could not run has not verified anything. Failing closed is the only
            // safe direction: the alternative silently promotes "we could not check" to "it is fine".
            $this->record(false, 'Verification could not run: ' . $e->getMessage());

            $this->plan->emitLedgerEvent(
                eventType: 'plan.verification.failed_to_run',
                status: EventStatusEnum::ERROR,
                payload: ['plan_id' => $this->plan->getId()],
                error: ['message' => $e->getMessage(), 'class' => $e::class],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000.0),
            );

            return false;
        }

        $passed = str_contains(strtoupper($verdict), self::PASS_TOKEN);

        $this->record($passed, $verdict);
        $this->settle($passed);

        $this->plan->emitLedgerEvent(
            'plan.verification.completed',
            payload: [
                'plan_id' => $this->plan->getId(),
                'verified' => $passed,
            ],
            durationMs: (int) ((microtime(true) - $startedAt) * 1000.0),
        );

        return $passed;
    }

    private function prompt(): string
    {
        $tasks = $this->plan->tasks()
            ->where('is_deleted', 0)
            ->orderBy('sequence')
            ->get()
            // Both ends of the report, not the first 400 characters. The verifier is deciding whether
            // the plan met its objective FROM this text, and workers narrate their method before stating
            // the result — a head-only trim hands it the preamble and hides the conclusion it is meant
            // to check.
            ->map(function (Task $task): string {
                $reported = $task->workerSummaryExcerpt(self::REPORTED_CHAR_CAP);

                return sprintf(
                    '- [%s] %s%s',
                    $task->status,
                    $task->title,
                    $reported !== null ? ' — reported: ' . $reported : '',
                );
            })
            ->implode("\n");

        return sprintf(
            "[NS:verify] plan_id=%d\n\n"
            . "You are checking someone else's work. Your job is to REFUTE the claim that this plan met "
            . "its objective, not to confirm it.\n\n"
            . "Objective: %s\n%s\nWhat the tasks claim:\n%s\n\n"
            . 'Check the underlying records yourself — you have read-only tools and no ability to change '
            . 'anything, deliberately. Do not take the task summaries at face value; they are the claim '
            . "under test.\n\n"
            . 'Answer with the single word %s ONLY if you can positively confirm the objective was met. '
            . 'If you cannot confirm it, or the evidence is ambiguous, or you could not check, say what is '
            . 'missing instead. Ambiguity is NOT verification.',
            $this->plan->getId(),
            $this->plan->title,
            $this->plan->description !== null && $this->plan->description !== ''
                ? 'Detail: ' . $this->plan->description . "\n"
                : '',
            $tasks !== '' ? $tasks : '(no tasks recorded)',
            self::PASS_TOKEN,
        );
    }

    private function record(bool $passed, string $verdict): void
    {
        $this->plan->output = [
            ...(is_array($this->plan->output) ? $this->plan->output : []),
            'verification' => [
                'verified' => $passed,
                'verdict' => Str::limit($verdict, 4000),
                'checked_at' => Carbon::now()->toIso8601String(),
            ],
        ];
        $this->plan->saveQuietly();
    }

    private function settle(bool $passed): void
    {
        $this->plan->status = $passed
            ? PlanStatusEnum::DONE->value
            : PlanStatusEnum::BLOCKED->value;

        if ($passed) {
            $this->plan->completed_at = Carbon::now();
            $this->plan->completion_pct = 100;
        } else {
            $this->plan->error_message = 'Verification did not confirm the objective was met. A human needs '
                . 'to review before this continues.';
        }

        $this->plan->saveQuietly();
    }
}
