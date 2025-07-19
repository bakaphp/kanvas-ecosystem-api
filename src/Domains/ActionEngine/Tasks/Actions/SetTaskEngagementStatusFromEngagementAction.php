<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Exceptions\ValidationException;

class SetTaskEngagementStatusFromEngagementAction
{
    public function __construct(
        protected Engagement $engagement,
        protected string $status,
    ) {
    }

    public function execute(): array
    {
        $this->validateInput();

        $results = [];
        $taskListItems = $this->findTaskListItems()->get();

        if ($taskListItems->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No task list items found for the engagement company action.',
                'results' => [],
            ];
        }

        foreach ($taskListItems as $taskListItem) {
            $results[] = $this->processTaskListItem($taskListItem);
        }

        return $results;
    }

    protected function validateInput(): void
    {
        if (! TaskStatusEnum::validate($this->status)) {
            throw new ValidationException('Invalid Task Status: ' . $this->status);
        }

        if (! $this->engagement->lead) {
            throw new ValidationException('Engagement must have an associated lead');
        }

        if (! $this->engagement->companyAction) {
            throw new ValidationException('Engagement must have an associated company action');
        }
    }

    protected function findTaskListItems(): Builder
    {
        return TaskListItem::where('companies_action_id', $this->engagement->companies_actions_id)
            ->where('is_deleted', 0);
    }

    protected function processTaskListItem(TaskListItem $taskListItem): TaskEngagementItem
    {
        $changeStatusAction = new ChangeTaskEngagementItemStatusAction(
            taskListItem: $taskListItem,
            lead: $this->engagement->lead,
            status: $this->status,
            user: $this->engagement->user,
            app: $this->engagement->app,
            company: $this->engagement->company,
            message: $this->engagement->message ?? null
        );

        return $changeStatusAction->execute();
    }
}
