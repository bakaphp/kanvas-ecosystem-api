<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\PresentsEntityFiles;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Everything a worker was handed for one task, including the plan's own files.
 *
 * A brief that applies to the whole plan is attached once at plan level, so a listing scoped
 * strictly to the task would show an empty list for a file that is right there.
 */
#[AgentTool(name: 'List Task Files', category: 'nervous_system')]
class ListNervousSystemTaskFilesTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use PresentsEntityFiles;
    use ResolvesTaskForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_task_files',
            description: 'List the documents attached to a task and to the plan it belongs to — the inputs you '
                . 'were handed. Call this BEFORE starting work on any task that mentions a file, document, '
                . 'spreadsheet or export, then read_file the ones you need. If the file you were told about is '
                . 'not here, say so and block the task — do not describe contents you have not read.',
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
                name: 'task_id',
                type: PropertyType::INTEGER,
                description: 'The task whose files you want.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $task_id): array
    {
        $task = $this->resolveTaskOrError($task_id);

        if (is_array($task)) {
            return $task;
        }

        $plan = $task->plan;

        $files = [
            ...$this->presentFiles($task, 'task'),
            ...($plan !== null ? $this->presentFiles($plan, 'plan') : []),
        ];

        return [
            'task_id' => $task->getId(),
            'plan_id' => $plan?->getId(),
            ...$this->fileListing(
                $files,
                'No files are attached to this task or its plan. If the work depends on a document nobody '
                    . 'attached, block the task and say which document is missing.',
            ),
        ];
    }
}
