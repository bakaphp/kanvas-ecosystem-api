<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Tasks;

use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Tasks\Actions\CreateTaskListItemAction;
use Kanvas\ActionEngine\Tasks\Actions\UpdateTaskListItemAction;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskListItem as TaskListItemData;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;

class TaskListItemMutation
{
    public function create(mixed $rootValue, array $request): TaskListItem
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $taskList = TaskList::getByIdFromCompanyApp(
            (int) $input['task_list_id'],
            $company,
            $app
        );
        $companyAction = CompanyAction::getByIdFromCompanyApp(
            (int) $input['companies_action_id'],
            $company,
            $app
        );

        return new CreateTaskListItemAction(
            new TaskListItemData(
                taskList: $taskList,
                companyAction: $companyAction,
                name: $input['name'],
                status: $input['status'] ?? null,
                config: $input['config'] ?? null,
                weight: isset($input['weight']) ? (float) $input['weight'] : 0,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): TaskListItem
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $taskListItem = TaskListItem::findOrFail((int) $request['id']);
        $taskList = TaskList::getByIdFromCompanyApp(
            (int) $input['task_list_id'],
            $company,
            $app
        );
        $companyAction = CompanyAction::getByIdFromCompanyApp(
            (int) $input['companies_action_id'],
            $company,
            $app
        );

        return new UpdateTaskListItemAction(
            $taskListItem,
            new TaskListItemData(
                taskList: $taskList,
                companyAction: $companyAction,
                name: $input['name'] ?? $taskListItem->name,
                status: $input['status'] ?? null,
                config: $input['config'] ?? null,
                weight: isset($input['weight']) ? (float) $input['weight'] : 0,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $taskListItem = TaskListItem::findOrFail((int) $request['id']);

        if (TaskEngagementItem::where('task_list_item_id', $taskListItem->getId())->where('is_deleted', 0)->exists()) {
            throw new ValidationException('Cannot delete task list item that is in use by task engagement items.');
        }

        return $taskListItem->softDelete();
    }
}
