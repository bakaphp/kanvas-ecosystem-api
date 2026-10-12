<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\GraphQL\Concerns\ActingContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Models\BaseModel;

/**
 * Files hang on a project, a task or a subtask alike, so the attachment tools take the target as a
 * (type, id) pair instead of existing three times over.
 */
trait ResolvesAttachmentTarget
{
    private const array ATTACHMENT_TARGETS = ['project', 'task', 'subtask'];

    /**
     * @return array<string, Type>
     */
    protected function attachmentTargetSchema(JsonSchema $schema): array
    {
        return [
            'target_type' => $schema->string()
                ->enum(self::ATTACHMENT_TARGETS)
                ->description('What the file belongs to.')
                ->required(),
            'target_id' => $schema->integer()
                ->description('Id of that project, task or subtask.')
                ->required(),
        ];
    }

    protected function attachmentTarget(string $type, int $id, ActingContext $ctx): BaseModel
    {
        return match ($type) {
            'project' => $this->findProject($id, $ctx),
            'task' => $this->findTask($id, $ctx),
            'subtask' => $this->findSubtask($id, $ctx),
            default => throw new ValidationException(
                'target_type must be one of: ' . implode(', ', self::ATTACHMENT_TARGETS) . '.'
            ),
        };
    }
}
