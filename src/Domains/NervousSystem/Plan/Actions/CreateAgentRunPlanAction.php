<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

/**
 * One background run an agent owns, recorded as a Plan with a single Task.
 *
 * Every async agent backend needs exactly this and each had written its own copy — pi.dev, Claude and
 * the harness, differing only in the plan type and what went into `input`. The shapes had already
 * started to drift (`'coding_job'` as a bare literal in one, a class constant in another), which is how
 * a board filter silently stops matching one of them.
 *
 * The Task, not the Plan, is the unit the pollers mirror onto, so it is what this returns.
 */
class CreateAgentRunPlanAction
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $brief,
        private readonly string $planType,
        private readonly ?Users $requestedBy = null,
        private readonly array $input = [],
        private readonly string $titlePrefix = 'Coding: ',
        private readonly ?Session $session = null,
        /**
         * The task this run was delegated from, when the dispatch happened inside a task-driven turn.
         * Its outcome resumes that task; the plan it belongs to becomes this run's parent plan.
         */
        private readonly ?Task $parentTask = null,
        /** The plan the dispatching turn ran under, for a delegation made outside any one task. */
        private readonly ?Plan $parentPlan = null,
    ) {
    }

    /**
     * Two links that must agree: the task link is what wakes the parent, the plan link and the project
     * are what the heartbeat traverses. A run with only one of them is invisible to the other.
     */
    private function recordLineage(Plan $plan, Task $task): void
    {
        $parentPlan = $this->parentPlan ?? $this->parentTask?->plan;

        if ($parentPlan !== null) {
            $plan->parent_plan_id = $parentPlan->getId();
            $plan->project_id ??= $parentPlan->project_id;
        }

        if ($this->parentTask !== null) {
            $task->parent_task_id = $this->parentTask->getId();
            $task->parent_revision = (int) $this->parentTask->revision;
        }
    }

    /**
     * Where the work was asked for, and by whom.
     *
     * Without this the outcome reaches nobody: `users_id` on an agent-owned run is the AGENT's user, so
     * the completion notification goes to a bot, and the Activities channel has no subscribers. These
     * three fields are what `AnnouncesPlanOutcome` reads to answer in the conversation the request came
     * from — the channel alone is not enough, because a chat thread is session-scoped and a report
     * posted without the session renders outside it.
     */
    private function recordOrigin(Plan $plan): void
    {
        // The asker is known even when no conversation is: a run opened from a scheduled turn still
        // has a person to tell when it ends.
        $plan->origin_users_id = $this->requestedBy?->getId();

        if ($this->session === null) {
            return;
        }

        $plan->origin_channel_id = $this->session->channel_id;
        $plan->origin_session_id = $this->session->getId();

        $sessionUser = $this->session->user;

        if (is_array($sessionUser) && isset($sessionUser['id'])) {
            $plan->origin_users_id = (int) $sessionUser['id'];
        }
    }

    public function execute(): Task
    {
        $brief = trim($this->brief);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->agent->app,
                company: $this->agent->company,
                title: $this->titlePrefix . Str::limit($brief, 80),
                planType: $this->planType,
                agent: $this->agent,
                // Without this `AnnouncesPlanOutcome` has no agent to speak as and silently skips the
                // transcript, the broadcast and the Slack push: the asker only ever got the direct
                // notification.
                createdByAgent: $this->agent,
                // The requesting human when there is one: a plan owned by the agent's own user is a
                // plan whose completion notification goes to a bot.
                user: $this->requestedBy ?? $this->agent->user,
                description: $brief,
                input: $this->input,
            ),
            tasks: [
                new TaskData(
                    plan: null,
                    title: Str::limit($brief, 120),
                    description: $brief,
                    status: TaskStatusEnum::IN_PROGRESS,
                ),
            ],
            fromSync: true,
        )->execute();

        /** @var Task $task */
        $task = $plan->tasks->first();
        // Not on the DTO: TaskData has no agent, and the board's "whose work is this" column reads it.
        $task->agent_id = $this->agent->getId();
        // Born in_progress, so the transition never minted its attempt; the outcome row needs one.
        $task->current_attempt_id = Str::uuid()->toString();

        $this->recordOrigin($plan);
        $this->recordLineage($plan, $task);
        $plan->saveQuietly();
        $task->saveQuietly();

        return $task;
    }
}
