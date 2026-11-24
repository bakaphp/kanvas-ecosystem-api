<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryStage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Puts a factory stage on the project's board: the plan, its tasks in order, each handed to its agent.
 * The task-ordering gate holds every step until the one before it is done, and the orchestrator is woken
 * when the last one finishes. Starting a stage that is already open returns that plan.
 */
class StartFactoryStageAction
{
    private bool $reused = false;

    public function __construct(
        private readonly FactoryStage $stage,
        private readonly Agent $orchestrator,
        private readonly ?Session $session = null,
    ) {
    }

    public function reused(): bool
    {
        return $this->reused;
    }

    public function execute(): Plan
    {
        $project = $this->stage->project;

        // The same ticket under a slightly different short name ("login screen" / "login-screen") is still
        // the same ticket, and must not run its design and build twice.
        $open = Plan::query()
            ->where('project_id', $project->getId())
            ->when(
                $this->stage->sameStageLike !== null,
                fn ($query) => $query->where('title', 'like', $this->stage->sameStageLike),
                fn ($query) => $query->where('title', $this->stage->title),
            )
            ->open()
            ->notDeleted()
            ->first();

        if ($open instanceof Plan) {
            $this->reused = true;

            return $open;
        }

        // A plan owner that is not a factory agent is woken on the plan, and its task is woken as well: two
        // paid turns for one step. Such a stage runs through its task only, so the plan has no owner.
        $firstAgent = $this->stage->steps[0]->agent;

        $plan = new CreatePlanAction(
            new PlanData(
                app: $project->app,
                company: $project->company,
                title: $this->stage->title,
                planType: 'project_work',
                agent: CodingChatSettingEnum::isEnabledFor($firstAgent) ? $firstAgent : null,
                user: $this->orchestrator->user,
                description: $this->stage->description,
                status: PlanStatusEnum::ACTIVE,
                project: $project,
                createdByAgent: $this->orchestrator,
            ),
        )->execute();

        $plan->rememberOrigin($this->session);

        $tasks = [];

        foreach ($this->stage->steps as $index => $step) {
            $tasks[] = [
                new AddTaskAction(
                    $plan,
                    new TaskData(
                        plan: $plan,
                        title: $step->title,
                        sequence: $index + 1,
                        description: $step->instructions,
                    ),
                )->execute(),
                $step->agent,
            ];
        }

        // Handed over only once every step exists, so the first wake already sees the whole line.
        foreach ($tasks as [$task, $agent]) {
            $task->handTo($agent);
        }

        $project->recomputeCompletionPct();

        return $plan->refresh();
    }
}
