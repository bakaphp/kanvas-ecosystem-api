<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\DispatchTaskBandAction;
use Kanvas\NervousSystem\Plan\Actions\PlanContinuationAction;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Actions\VerifyPlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\ContinuationDecision;
use Kanvas\NervousSystem\Plan\Enums\ContinuationDecisionEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\PlanLoopSettings;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\Social\Messages\Models\Message;

/**
 * Single entry point for waking the agent assigned to a Plan. Used by:
 *   - WakeAgentOnPlanChangeListener (plan created / approved)
 *   - ReplyToPlanCommentActivity (human comment on the Activities channel)
 *
 * Both paths land on the same per-plan Session so the agent's LLM context
 * is continuous across all wake-ups for the same plan.
 *
 * Emits two ledger events on success:
 *   plan.agent.invoked      — after the LLM call returns (carries duration_ms)
 *   plan.agent.replied      — after the reply is posted on the channel
 *
 * And one on failure:
 *   plan.agent.invocation_failed — when the agent turn throws
 */
class WakeAgentForPlanJob implements ShouldQueue
{
    use Dispatchable;
    use DrivesAgentWake;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string REASON_PLAN_ASSIGNED = 'plan_assigned';
    public const string REASON_COMMENT = 'comment';
    public const string REASON_APPROVED = 'approved';

    /**
     * A delegated task reached a terminal state. The completion rides `$userMessage` rather than
     * being left for the agent to diff out of its context bundle — `ProjectContextService::plans()`
     * is scoped `->open()`, so a plan that rolls up complete drops out entirely and the very fact
     * the agent needs is the one that vanishes.
     */
    public const string REASON_TASK_COMPLETED = 'task_completed';

    /**
     * A finished task was put back to `pending` — someone wants it done again, usually because the
     * first attempt failed for a reason that has since been fixed. Distinct from completion because
     * the instruction is the opposite: run the work, do not report and close out.
     */
    public const string REASON_TASK_REOPENED = 'task_reopened';

    /** Enough to see a board, short of pasting a long plan into every wake. */
    private const int INVENTORY_LIMIT = 30;

    public function __construct(
        public readonly Plan $plan,
        public readonly string $reason,
        public readonly ?string $userMessage = null,
    ) {
    }

    public function handle(): void
    {
        // Reset Bouncer scope + app to this plan's app — else the agent/channel Role lookups
        // throw ModelNotFoundException under a leaked worker scope.
        $this->overwriteAppService($this->plan->app);

        $agent = $this->plan->agent;
        $owner = $this->plan->user ?? $agent?->user;

        if ($agent === null || $owner === null) {
            return;
        }

        $session = $this->resolveSession();

        // Counted before the turn, not after: a turn that crashes still consumed a re-entry, and a
        // plan that crashes every time is exactly what the budget exists to stop.
        $this->plan->increment('wake_count');
        $this->plan->refresh();

        $decision = PlanLoopSettings::continuationEnabled($agent)
            ? new PlanContinuationAction($this->plan)->execute()
            : null;

        if ($decision !== null) {
            $this->plan->emitLedgerEvent('plan.continuation.decided', payload: $decision->toLedgerPayload());

            // DISPATCH and VERIFY are the two verdicts the loop can act on by itself; the rest are
            // states a human or the agent's own reply resolves. Acting here rather than asking the
            // agent to is the difference between a decision and a suggestion.
            if ($decision->verdict === ContinuationDecisionEnum::DISPATCH) {
                new DispatchTaskBandAction($this->plan)->execute();
            }

            if ($decision->verdict === ContinuationDecisionEnum::VERIFY) {
                new VerifyPlanAction($this->plan)->execute();

                // Verification settles the plan itself — DONE on a pass, BLOCKED on anything else — so
                // there is nothing left for this turn to tell the agent to do.
                return;
            }
        }

        $failurePayload = [
            'agent_id' => $this->plan->agent_id,
            'session_id' => $session->getId(),
            'reason' => $this->reason,
        ];

        [$response, $durationMs] = $this->runAgentWake(
            $agent,
            $session,
            $owner,
            $this->buildMessage($decision),
            $this->plan,
            'plan.agent',
            $failurePayload,
        );

        $this->plan->emitLedgerEvent(
            'plan.agent.invoked',
            payload: $failurePayload + ['response_length' => strlen($response)],
            durationMs: $durationMs,
        );

        $reply = $this->postReplyOnActivitiesChannel($response);

        if ($reply !== null) {
            $this->plan->emitLedgerEvent(
                'plan.agent.replied',
                payload: [
                    'agent_id' => $this->plan->agent_id,
                    'message_id' => $reply->id,
                    'message_uuid' => $reply->uuid,
                ],
            );
        }
    }

    /**
     * Keyed on the PLAN alone, deliberately: every wake reason for a plan shares one continuous LLM
     * thread. (The worker path keys per-agent instead, so a reassigned plan does not inherit its
     * predecessor's "I am blocked" thread.)
     */
    protected function resolveSession(): Session
    {
        $owner = $this->plan->user ?? $this->plan->agent?->user;

        return $this->firstOrCreateWakeSession(
            $this->plan,
            create: [
                'agents_id' => $this->plan->agent_id,
                'user' => $owner !== null ? [
                    'id' => $owner->getId(),
                    'name' => trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? '')),
                    'email' => $owner->email ?? null,
                ] : [],
            ],
        );
    }

    /**
     * What is already on the board, named.
     *
     * Without it an agent re-decomposes work it cannot see: one plan filed "Count leads missing email"
     * and "Audit leads missing email" as separate tasks. Re-wording is how a duplicate escapes a title
     * check, so the fix is showing the board rather than policing the writes.
     *
     * Blocked reasons are included because a task blocked for a missing tool will block again.
     */
    private function taskInventory(): string
    {
        $tasks = $this->plan->tasks()
            ->where('is_deleted', 0)
            ->orderBy('sequence')
            ->orderBy('id')
            ->limit(self::INVENTORY_LIMIT)
            ->get();

        if ($tasks->isEmpty()) {
            return '';
        }

        $lines = $tasks->map(static function (Task $task): string {
            $line = sprintf('  #%d seq=%d [%s] %s', $task->getId(), $task->sequence, $task->status, $task->title);

            return $task->blocked_reason !== null && $task->blocked_reason !== ''
                ? $line . ' — blocked: ' . Str::limit($task->blocked_reason, 160)
                : $line;
        })->implode("\n");

        return sprintf(
            "\nTasks already on this plan — do NOT create these again, in any wording:\n%s\n",
            $lines,
        );
    }

    protected function buildMessage(?ContinuationDecision $decision = null): string
    {
        // When the loop is on, the verdict IS the instruction. The prose branches below stay for
        // agents that have not been switched over — running both would give the agent two sets of
        // orders, which is how it ends up following neither.
        if ($decision !== null) {
            $header = sprintf(
                '[NS:continuation] plan_id=%d plan_uuid=%s verdict=%s',
                $this->plan->id,
                $this->plan->uuid,
                $decision->verdict->value,
            );

            $state = sprintf(
                "Plan state: %s\n%s%s",
                $decision->reason,
                $this->taskInventory(),
                $decision->verdict->instruction(),
            );

            // A human comment is a question addressed to the agent, so it leads. Putting the verdict
            // first would have the agent answer the board instead of the person who just asked it
            // something — the state is context for the reply, not a replacement for it.
            if ($this->reason === self::REASON_COMMENT && $this->userMessage !== null && $this->userMessage !== '') {
                return sprintf("%s\n\n%s\n\n---\n%s", $header, $this->userMessage, $state);
            }

            return $this->userMessage !== null && $this->userMessage !== ''
                ? sprintf("%s\n\n%s\n\nWhat just happened: %s", $header, $state, $this->userMessage)
                : sprintf("%s\n\n%s", $header, $state);
        }

        if ($this->reason === self::REASON_COMMENT) {
            return sprintf(
                "[NS:plan_comment] plan_id=%d plan_uuid=%s\n\n%s",
                $this->plan->id,
                $this->plan->uuid,
                (string) $this->userMessage,
            );
        }

        if ($this->reason === self::REASON_TASK_REOPENED) {
            return sprintf(
                "[NS:task_reopened] plan_id=%d plan_uuid=%s\n\n%s\n\n"
                . 'A task on this plan was reset to pending, which means someone wants it RUN AGAIN — '
                . 'whatever blocked it the first time is expected to be fixed now. Do the work or '
                . 'dispatch it to the assignee. Do not report it as already finished, and do not wait '
                . 'for anything else to pick it up: nothing else will.',
                $this->plan->id,
                $this->plan->uuid,
                (string) $this->userMessage,
            );
        }

        if ($this->reason === self::REASON_TASK_COMPLETED) {
            return sprintf(
                "[NS:task_completed] plan_id=%d plan_uuid=%s\n\n%s\n\n"
                . 'Follow up on this: report it to whoever asked, assign any review or next step, '
                . 'and close the plan if the work is finished.',
                $this->plan->id,
                $this->plan->uuid,
                (string) $this->userMessage,
            );
        }

        if ($this->reason === self::REASON_APPROVED) {
            $reviewerNote = $this->plan->review_outcome !== null && $this->plan->review_outcome !== ''
                ? "Reviewer note: {$this->plan->review_outcome}\n"
                : '';

            return sprintf(
                "[NS:plan_approved] plan_id=%d plan_uuid=%s\n\n"
                . 'Your plan has been approved by the human reviewer. '
                . 'Resume execution. Use the nervous-system-working skill '
                . "to refresh plan context if you need to, then continue the work.\n\n"
                // Its own history still says "waiting on approval", so without this it re-blocks on
                // the condition that just cleared and the loop stops again on the next verdict.
                . 'ANY TASK YOU BLOCKED WAITING FOR THIS APPROVAL IS ALREADY BACK TO pending — the '
                . 'approval you were waiting for is the one that just arrived. Do NOT re-block for it '
                . "and do not ask for it again; pick the work up and do it.\n\n"
                . "Title: %s\n%s%s",
                $this->plan->id,
                $this->plan->uuid,
                $this->plan->title,
                $reviewerNote,
                $this->plan->description !== null && $this->plan->description !== ''
                    ? "Description: {$this->plan->description}"
                    : '',
            );
        }

        return sprintf(
            "[NS:plan_assigned] reason=%s plan_id=%d plan_uuid=%s\n\n"
            . 'A plan has been assigned to you. Use the nervous-system-working '
            . 'skill to fetch its full context, plan the work, and execute. '
            . "Report progress on the Activities channel.\n\n"
            . "Title: %s\n%s",
            $this->reason,
            $this->plan->id,
            $this->plan->uuid,
            $this->plan->title,
            $this->plan->description !== null && $this->plan->description !== ''
                ? "Description: {$this->plan->description}"
                : '',
        );
    }

    protected function postReplyOnActivitiesChannel(string $response): ?Message
    {
        return new PostPlanActivityMessageAction(
            $this->plan,
            $response,
            author: $this->plan->agent?->user,
        )->execute();
    }
}
