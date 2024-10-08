<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

class TaskEngagementMutation
{
    public function changeEngagementTaskItemStatus(mixed $rootValue, array $request): bool
    {
        $id = (int) $request['id'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $status = $request['status'];
        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);

        $taskListItem = TaskListItem::getById($id);

        if ($taskListItem->companyAction->companies_id != $company->getId()) {
            throw new ValidationException('You are not allowed to change the status of this task , company mismatch');
        }

        if ($taskListItem->companyAction->apps_id != $app->getId()) {
            throw new ValidationException('You are not allowed to change the status of this task , app mismatch');
        }

        $taskEngagementItem = TaskEngagementItem::fromCompany($company)
            ->fromApp($app)
            ->where('task_list_item_id', $taskListItem->getId())
            ->where('lead_id', $lead->getId())
            ->first();

        if (! $taskEngagementItem) {
            $taskEngagementItem = new TaskEngagementItem();
            $taskEngagementItem->task_list_item_id = $taskListItem->getId();
            $taskEngagementItem->lead_id = $lead->getId();
            $taskEngagementItem->companies_id = $company->getId();
            $taskEngagementItem->apps_id = $app->getId();
            $taskEngagementItem->users_id = $user->getId();
        }

        $taskEngagementItem->status = $status;
        $saveTaskEngagementItem = $taskEngagementItem->saveOrFail();

        /**
         * @todo move to observer
         */
        $taskEngagementItem->disableRelatedItems();

        return $saveTaskEngagementItem;
    }
}
