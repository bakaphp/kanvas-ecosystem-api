<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Closure;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\StartFactoryStageAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryStage;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * The shared half of the factory stage tools: the host builds the stage, this puts it on the board and
 * tells the orchestrator what now runs and what it still owes the person.
 *
 * Hosts use HasKanvasContext, ResolvesProjectForTool and ReportsToolOutcome, and hold the `$session`.
 */
trait StartsFactoryStage
{
    /**
     * @param Closure(Project): FactoryStage $build
     *
     * @return array<string, mixed>
     */
    protected function startStage(int $projectId, Closure $build): array
    {
        $project = $this->resolveProjectOrError($projectId);

        if (is_array($project)) {
            return $project;
        }

        $orchestrator = $this->contextAgent();

        if ($orchestrator === null) {
            return $this->denied('Only an agent can start a factory stage.');
        }

        try {
            $action = new StartFactoryStageAction(
                $build($project),
                $orchestrator,
                $this->session,
            );
            $plan = $action->execute();
        } catch (ValidationException $e) {
            return $this->invalidArgs($e->getMessage(), guidance: 'Nothing was started. Fix that and call it again.');
        }

        $steps = $plan->tasks()
            ->whereNull('parent_task_id')
            ->orderBy('sequence')
            ->get()
            ->map(fn (Task $task): array => [
                'task_id' => $task->getId(),
                'title' => $task->title,
                'agent' => $task->agent?->name,
                'status' => $task->status,
            ])
            ->all();

        $payload = [
            'plan_id' => $plan->getId(),
            'title' => $plan->title,
            'reused' => $action->reused(),
            'steps' => $steps,
        ];

        return $this->ok(
            $payload,
            $action->reused()
                ? 'This stage is already on the board as plan ' . $plan->getId() . '. Nothing new was started: tell '
                    . 'the person where it stands.'
                : 'Started. The steps run on the factory Mac in this order, already assigned: create, assign or '
                    . 'edit nothing more for them. You are woken when the last step finishes. A kickoff or a ticket '
                    . 'lands on the integration branch: start the next ticket of the approved plan without asking, '
                    . 'or start_factory_release after the last one. A release ends with a pull request into develop: '
                    . 'give the person its link, the one thing they review and merge.'
        );
    }
}
