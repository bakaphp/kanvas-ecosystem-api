<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;

class TaskEngagementMutation
{
    public function changeEngagementTaskItemStatus(mixed $rootValue, array $request): bool
    {
        $id = (int) $request['id'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $status = $request['status'];

        $taskListItem = TaskListItem::getById($id);

        if ($taskListItem->companyAction->company_id != $company->getId()) {
            throw new ValidationException('You are not allowed to change the status of this task');
        }

        if ($taskListItem->companyAction->apps_id != $app->getId()) {
            throw new ValidationException('You are not allowed to change the status of this task');
        }

        $taskListItem->status = $status;

        return $taskListItem->saveOrFail();
    }
}
