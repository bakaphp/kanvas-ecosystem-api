<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\PresentsEntityFiles;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Models\Task;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Every document on a plan: its own briefs, plus whatever its tasks produced.
 *
 * Deliverables land on the task that produced them, so a verifier reading only the plan would
 * conclude nothing was delivered. The roll-up is what makes "did this produce the artifact it
 * claims?" answerable from evidence rather than from prose.
 */
#[AgentTool(name: 'List Plan Files', category: 'nervous_system')]
class ListNervousSystemPlanFilesTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use PresentsEntityFiles;
    use ResolvesPlanForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_plan_files',
            description: 'List every document on a plan — the briefs attached to the plan itself and the '
                . 'artifacts its tasks produced. Use it to find the input a plan references, and to check what '
                . 'was actually delivered before closing or verifying. Then read_file the ones you need; a file '
                . 'you have not read is not evidence.',
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
                description: 'The plan whose files you want.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id): array
    {
        $plan = $this->resolvePlanOrError($plan_id);

        if (is_array($plan)) {
            return $plan;
        }

        $files = $this->presentFiles($plan, 'plan');

        foreach ($plan->tasks()->where('is_deleted', 0)->orderBy('sequence')->orderBy('id')->get() as $task) {
            /** @var Task $task */
            foreach ($this->presentFiles($task, 'task') as $file) {
                $files[] = $file + ['task_id' => $task->getId(), 'task_title' => $task->title];
            }
        }

        return [
            'plan_id' => $plan->getId(),
            'plan_title' => $plan->title,
            ...$this->fileListing(
                $files,
                'No files are attached to this plan or any of its tasks. If the plan references a document, it '
                    . 'was never attached — say so rather than assuming its contents.',
            ),
        ];
    }
}
