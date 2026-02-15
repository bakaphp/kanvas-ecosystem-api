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

        $taskListItem = TaskListItem::query()
            ->join('company_task_list', 'company_task_list_items.task_list_id', '=', 'company_task_list.id')
            ->where('company_task_list.companies_id', $company->getId())
            ->where('company_task_list.apps_id', $app->getId())
            ->where('company_task_list.is_deleted', 0)
            ->select('company_task_list_items.*')
            ->findOrFail((int) $request['id']);
        $taskList = isset($input['task_list_id'])
            ? TaskList::getByIdFromCompanyApp((int) $input['task_list_id'], $company, $app)
            : $taskListItem->task;
        $companyAction = CompanyAction::getByIdFromCompanyApp(
            (int) ($input['companies_action_id'] ?? $taskListItem->companies_action_id),
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
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $taskListItem = TaskListItem::query()
            ->join('company_task_list', 'company_task_list_items.task_list_id', '=', 'company_task_list.id')
            ->where('company_task_list.companies_id', $company->getId())
            ->where('company_task_list.apps_id', $app->getId())
            ->where('company_task_list.is_deleted', 0)
            ->select('company_task_list_items.*')
            ->findOrFail((int) $request['id']);

        if (TaskEngagementItem::where('task_list_item_id', $taskListItem->getId())->where('is_deleted', 0)->exists()) {
            throw new ValidationException('Cannot delete task list item that is in use by task engagement items.');
        }

        return $taskListItem->softDelete();
    }
}
