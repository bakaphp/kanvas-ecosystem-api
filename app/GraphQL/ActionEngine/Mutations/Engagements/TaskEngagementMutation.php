<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class TaskEngagementMutation
{
    public function changeEngagementTaskItemStatus(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();

        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        $app = app(Apps::class);

        /** @var TaskListItem $taskListItem */
        $taskListItem = TaskListItem::getById((int) $request['id']);

        /** @var Lead $lead */
        $lead = Lead::getByIdFromCompanyApp((int) $request['lead_id'], $company, $app);

        /** @var Message|null $message */
        $message = ! empty($request['message_id'])
            ? Message::getByIdFromCompanyApp((int) $request['message_id'], $company, $app)
            : null;

        new ChangeTaskEngagementItemStatusAction(
            taskListItem: $taskListItem,
            lead: $lead,
            status: $request['status'],
            user: $user,
            app: $app,
            company: $company,
            message: $message,
            config: $request['config'] ?? null,
        )->execute();

        return true;
    }
}
