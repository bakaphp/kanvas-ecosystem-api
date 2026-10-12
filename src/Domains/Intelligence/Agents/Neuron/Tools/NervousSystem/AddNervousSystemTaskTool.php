<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Lets an in-process agent add a task to a plan — the "decompose the work" half of a PM's job. Wraps
 * AddTaskAction (recomputes plan completion, emits ledger events) so an agent-created task is a
 * first-class task like any other.
 */
#[AgentTool(name: 'Add Task', category: 'nervous_system')]
class AddNervousSystemTaskTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesPlanForTool;
    use ResolvesTaskForTool;

    protected string $name = 'add_nervous_system_task';

    protected ?string $description = 'Add a task to a Nervous System plan. Use this to break work down into concrete, '
        . 'assignable steps under a plan.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The id of the plan to add the task to.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Short, action-oriented task title.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional detail on what the task involves.',
                required: false,
            ),
            new ToolProperty(
                name: 'sequence',
                type: PropertyType::INTEGER,
                description: 'Ordering position, and also what runs in parallel: tasks sharing a sequence '
                    . 'number are worked AT THE SAME TIME, and a higher number waits until every lower one is '
                    . 'finished. Give the same number to tasks that are independent of each other (chasing '
                    . 'twelve different invoices), and increasing numbers when one task needs another\'s output '
                    . '(write the client, then write the tests). Omit only when you genuinely do not know — the '
                    . 'default makes every task wait for the one before it.',
                required: false,
            ),
            new ToolProperty(
                name: 'parent_task_id',
                type: PropertyType::INTEGER,
                description: 'The task on this plan that this one is a sub-step of, when you are splitting '
                    . 'work you own. The parent waits for it: when this task finishes, the parent is resumed '
                    . 'with the result instead of being left for someone to notice. Omit for a top-level task.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $plan_id,
        string $title,
        ?string $description = null,
        ?int $sequence = null,
        ?int $parent_task_id = null,
    ): array {
        $plan = $this->resolvePlanOrError(
            $plan_id,
            "Plan {$plan_id} was not found. Create a plan first, or use a plan_id from the context.",
        );

        if (is_array($plan)) {
            return $plan;
        }

        $parent = null;

        if ($parent_task_id !== null) {
            $parent = $this->resolveTaskOrError(
                $parent_task_id,
                "Task {$parent_task_id} was not found on this company. Use a task_id from the plan context.",
            );

            if (is_array($parent)) {
                return $parent;
            }

            if ($parent->plan_id !== $plan->getId()) {
                return ['error' => "Task {$parent_task_id} belongs to another plan; a sub-task goes on its parent's plan."];
            }

            if ($parent->isTerminal()) {
                return ['error' => "Task {$parent_task_id} is already {$parent->status}; reopen it before adding sub-tasks under it."];
            }
        }

        // Idempotency: don't re-add a task that already exists in this plan on a repeated wake.
        $existing = Task::query()
            ->where('plan_id', $plan->getId())
            ->where('title', $title)
            ->notDeleted()
            ->first();

        if ($existing !== null) {
            return [
                'task_id' => $existing->getId(),
                'plan_id' => $plan->getId(),
                'title' => $existing->title,
                'status' => $existing->status,
                'reused' => true,
                'message' => 'This task already exists on the plan. Do NOT call add_nervous_system_task '
                    . 'again for it — move on to a different task, or finish if the plan is fully broken down.',
            ];
        }

        $task = new AddTaskAction(
            $plan,
            new TaskData(
                plan: $plan,
                title: $title,
                sequence: $sequence ?? 0,
                description: $description,
                parentTask: $parent,
            ),
        )->execute();

        $plan->project?->recomputeCompletionPct();

        return [
            'task_id' => $task->getId(),
            'plan_id' => $plan->getId(),
            'title' => $task->title,
            'status' => $task->status,
            'parent_task_id' => $task->parent_task_id,
        ];
    }
}
