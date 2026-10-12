<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Attachments;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\PresentsProjectBoard;
use App\Mcp\Tools\Concerns\ResolvesAttachmentTarget;
use App\Mcp\Tools\KanvasTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
class ListFilesTool extends KanvasTool
{
    use PresentsProjectBoard;
    use ResolvesAttachmentTarget;

    protected string $name = 'list_files';

    protected string $description = 'List the files attached to a project, a task or a subtask, with a link to each. For '
        . 'a task the list also includes the files of its subtasks.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return $this->attachmentTargetSchema($schema);
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $input = $request->validate([
            'target_type' => ['required', 'string'],
            'target_id' => ['required', 'integer'],
        ]);

        $target = $this->attachmentTarget($input['target_type'], (int) $input['target_id'], $ctx);
        $files = $this->presentFiles($target);

        // Deliverables land on the subtask that produced them, so a task-level listing that left them
        // out would read as "nothing was delivered".
        if ($target instanceof Plan) {
            foreach ($target->tasks()->orderBy('sequence')->orderBy('id')->get() as $task) {
                /** @var Task $task */
                foreach ($this->presentFiles($task) as $file) {
                    $files[] = $file + ['subtask_id' => $task->getId(), 'subtask_title' => $task->title];
                }
            }
        }

        return [
            'target_type' => $input['target_type'],
            'target_id' => $target->getId(),
            'count' => count($files),
            'files' => $files,
        ];
    }
}
