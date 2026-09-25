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
    ) {
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
        if ($this->session === null) {
            return;
        }

        $plan->origin_channel_id = $this->session->channel_id;
        $plan->origin_session_id = $this->session->getId();

        $sessionUser = $this->session->user;
        $plan->origin_users_id = is_array($sessionUser) && isset($sessionUser['id'])
            ? (int) $sessionUser['id']
            : $this->requestedBy?->getId();

        $plan->saveQuietly();
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

        $this->recordOrigin($plan);

        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();
        // Not on the DTO: TaskData has no agent, and the board's "whose work is this" column reads it.
        $task->agent_id = $this->agent->getId();
        $task->saveQuietly();

        return $task;
    }
}
