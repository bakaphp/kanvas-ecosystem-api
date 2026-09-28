<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasFileUploadToolProperties;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Hand a task's deliverable back as a real file.
 *
 * The alternative is a report pasted into a plan comment, which no later agent can open and no
 * verifier can treat as an artifact.
 */
#[AgentTool(name: 'Attach File To Task', category: 'nervous_system')]
class AttachFileToNervousSystemTaskTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use HasFileUploadToolProperties;
    use HasKanvasContext;
    use ResolvesTaskForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'attach_file_to_task',
            description: 'Attach a document to a task — normally the deliverable you produced. Pass `content` '
                . 'with the full text you wrote plus a `file_name` ending in .md, .txt, .csv or .json; pass '
                . '`file_url` instead only when the file already exists at a public URL. Use this for any '
                . 'report, breakdown, export or write-up: a comment is for a short remark, a file is for the '
                . 'work product. Never claim you produced a document without attaching it.',
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
                description: 'The task to attach the file to.',
                required: true,
            ),
            ...$this->fileUploadProperties('headcount-breakdown.md'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $task_id,
        ?string $file_name = null,
        ?string $content = null,
        ?string $file_url = null,
    ): array {
        $task = $this->resolveTaskOrError($task_id);

        if (is_array($task)) {
            return $task;
        }

        return [
            'task_id' => $task->getId(),
            'plan_id' => $task->plan_id,
            ...$this->attachFileToEntity(
                entity: $task,
                entityLabel: 'task',
                fileUrl: $file_url,
                content: $content,
                fileName: $file_name,
            ),
        ];
    }
}
