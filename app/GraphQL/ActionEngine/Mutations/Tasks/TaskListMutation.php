<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Tasks;

use Kanvas\ActionEngine\Tasks\Actions\CreateTaskListAction;
use Kanvas\ActionEngine\Tasks\Actions\UpdateTaskListAction;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskList as TaskListData;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;

class TaskListMutation
{
    public function create(mixed $rootValue, array $request): TaskList
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreateTaskListAction(
            new TaskListData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                config: $input['config'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): TaskList
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $taskList = TaskList::getByIdFromCompanyApp(
            (int) $request['id'],
            $company,
            $app,
        );

        $input = $request['input'];

        return new UpdateTaskListAction(
            $taskList,
            new TaskListData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $taskList->name,
                config: $input['config'] ?? $taskList->config,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $taskList = TaskList::getByIdFromCompanyApp(
            (int) $request['id'],
            $user->getCurrentCompany(),
            $app,
        );

        return $taskList->softDelete();
    }
}
