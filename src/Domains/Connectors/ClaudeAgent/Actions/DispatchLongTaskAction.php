<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Illuminate\Support\Str;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

/**
 * Start work that outlives a request: create the Plan + Task, open a hosted session seeded with the
 * brief, and hand off to the poller.
 *
 * **Returns as soon as the session is open, never when the work is done.** A queue worker caps out
 * around an hour and a hosted session can legitimately run longer, so nothing here may block on the
 * agent. The task lands `in_progress`; only {@see PollClaudeSessionJob} ever writes a terminal
 * status. Anything that reports completion from here would be reporting a dispatch as a delivery.
 */
class DispatchLongTaskAction
{
    public const string PLAN_TYPE = 'claude_task';

    /**
     * @param string|null $rubric Turns this into a graded run: the platform iterates against the
     *                            rubric and only settles when it passes. Criteria must be checkable
     *                            **inside the sandbox** — it has no database and cannot run the
     *                            Kanvas test suite, so "tests pass" is ungradeable there and belongs
     *                            to CI instead.
     * @param list<string> $repoSlugs Restrict mounted repos; empty means the agent's whole allow-list.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly string $brief,
        protected readonly ?Users $requestedBy = null,
        protected readonly ?string $rubric = null,
        protected readonly array $repoSlugs = [],
        protected readonly ?int $maxIterations = null,
        protected readonly ?Client $client = null,
    ) {
    }

    public function execute(): Task
    {
        // Validate before the plan exists, so a bad brief or slug can't leave an orphan behind.
        $brief = StartHostedTaskSessionAction::assertDispatchable($this->agent, $this->brief, $this->repoSlugs);

        $task = $this->recordAsTask($brief);

        new StartHostedTaskSessionAction(
            task: $task,
            agent: $this->agent,
            brief: $brief,
            rubric: $this->rubric,
            repoSlugs: $this->repoSlugs,
            maxIterations: $this->maxIterations,
            client: $this->client,
        )->execute();

        return $task;
    }

    protected function recordAsTask(string $brief): Task
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->agent->app,
                company: $this->agent->company,
                title: 'Claude: ' . Str::limit($brief, 80),
                planType: self::PLAN_TYPE,
                agent: $this->agent,
                // The plan owner is the human who asked — that is who gets notified on completion,
                // and what makes PlanObserver create the Activities channel. Autonomous runs fall
                // back to the agent's own user.
                user: $this->requestedBy ?? $this->agent->user,
                description: $brief,
                status: PlanStatusEnum::ACTIVE,
                input: ['repo_slugs' => $this->repoSlugs, 'graded' => $this->rubric !== null],
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
        $task = $plan->tasks()->firstOrFail();
        $task->agent_id = $this->agent->getId();
        $task->saveQuietly();

        return $task;
    }
}
