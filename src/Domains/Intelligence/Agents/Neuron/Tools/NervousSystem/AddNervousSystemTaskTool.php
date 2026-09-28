<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use NeuronAI\Tools\HasRunKey;
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
class AddNervousSystemTaskTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;
    use ResolvesPlanForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'add_nervous_system_task',
            description: 'Add a task to a Nervous System plan. Use this to break work down into concrete, '
                . 'assignable steps under a plan.',
        );
    }

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
    ): array {
        $plan = $this->resolvePlanOrError(
            $plan_id,
            "Plan {$plan_id} was not found. Create a plan first, or use a plan_id from the context.",
        );

        if (is_array($plan)) {
            return $plan;
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
            TaskData::fromMultiple($plan, [
                'title' => $title,
                'description' => $description,
                'sequence' => $sequence,
            ]),
        )->execute();

        $plan->project?->recomputeCompletionPct();

        return [
            'task_id' => $task->getId(),
            'plan_id' => $plan->getId(),
            'title' => $task->title,
            'status' => $task->status,
        ];
    }
}
