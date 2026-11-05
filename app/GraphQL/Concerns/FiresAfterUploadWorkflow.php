<?php

declare(strict_types=1);

namespace App\GraphQL\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Workflow\Enums\WorkflowEnum;

trait FiresAfterUploadWorkflow
{
    use HasMutationUploadFiles;

    /**
     * `$target` may differ from `$entity` (a lead upload redirected to its person); the workflow still
     * fires on `$entity`, and only after the attach so an outbound sync sees the new file in the same run.
     */
    protected function attachFilesThenFireAfterUpload(
        Model $entity,
        Model $target,
        AppInterface $app,
        UserInterface $user,
        array $request
    ): void {
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $taskId = $params['task_id'] ?? null;
        $hasTask = (is_string($taskId) || is_int($taskId)) && ! empty($taskId);

        if ($hasTask) {
            $entity->set('checklist_upload', $taskId);
        }

        $this->uploadFileToEntity(
            model: $target,
            app: $app,
            user: $user,
            request: $request
        );

        $entity->fireWorkflow(
            WorkflowEnum::AFTER_UPLOAD->value,
            true,
            ($hasTask ? ['task_id' => $taskId] : []) + ['app' => $app]
        );
    }
}
