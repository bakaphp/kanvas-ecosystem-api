<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class ChangeTaskEngagementItemStatusAction
{
    public function __construct(
        protected TaskListItem $taskListItem,
        protected Lead $lead,
        protected string $status,
        protected Users $user,
        protected AppInterface $app,
        protected Companies $company,
        protected ?Message $message = null,
        protected ?array $config = null
    ) {
    }

    public function execute(): TaskEngagementItem
    {
        $this->validateInput();

        $taskEngagementItem = $this->getOrCreateTaskEngagementItem();

        $this->updateTaskEngagementItemStatus($taskEngagementItem);

        $this->handleRelatedTasks($taskEngagementItem);

        $this->fireWorkflow($taskEngagementItem);

        return $taskEngagementItem;
    }

    protected function validateInput(): void
    {
        if ($this->taskListItem->companyAction->companies_id !== $this->company->getId()) {
            throw new ValidationException('You are not allowed to change the status of this task, company mismatch');
        }

        if ($this->taskListItem->companyAction->apps_id !== $this->app->getId()) {
            throw new ValidationException('You are not allowed to change the status of this task, app mismatch');
        }

        if (! TaskStatusEnum::validate($this->status)) {
            throw new ValidationException('Invalid Task Status');
        }
    }

    protected function getOrCreateTaskEngagementItem(): TaskEngagementItem
    {
        $taskEngagementItem = TaskEngagementItem::fromCompany($this->company)
            ->fromApp($this->app)
            ->where('task_list_item_id', $this->taskListItem->getId())
            ->where('lead_id', $this->lead->getId())
            ->first();

        if (! $taskEngagementItem) {
            $taskEngagementItem = new TaskEngagementItem();
            $taskEngagementItem->task_list_item_id = $this->taskListItem->getId();
            $taskEngagementItem->lead_id = $this->lead->getId();
            $taskEngagementItem->companies_id = $this->company->getId();
            $taskEngagementItem->apps_id = $this->app->getId();
            $taskEngagementItem->users_id = $this->user->getId();
        }

        return $taskEngagementItem;
    }

    protected function updateTaskEngagementItemStatus(TaskEngagementItem $taskEngagementItem): void
    {
        // Handle engagement associations based on status
        if ($this->status === TaskStatusEnum::IN_PROGRESS->value && $this->message) {
            $engagement = $this->getEngagementFromMessage(ActionStatusEnum::SENT->value);
            if ($engagement && empty($taskEngagementItem->engagement_start_id)) {
                $taskEngagementItem->engagement_start_id = $engagement->getId();
            }
        }

        if ($this->status === TaskStatusEnum::COMPLETED->value && $this->message) {
            $engagement = $this->getEngagementFromMessage(ActionStatusEnum::SUBMITTED->value);
            if ($engagement && empty($taskEngagementItem->engagement_end_id)) {
                $taskEngagementItem->engagement_end_id = $engagement->getId();
            }
        }

        $taskEngagementItem->status = $this->status;

        if ($this->config !== null) {
            $taskEngagementItem->config = $this->config;
        }

        $taskEngagementItem->saveOrFail();
    }

    protected function getEngagementFromMessage(string $status): ?Engagement
    {
        if (! $this->message) {
            return null;
        }

        return Engagement::fromApp($this->app)
            ->fromCompany($this->company)
            ->where('message_id', $this->message->getId())
            ->first();
    }

    protected function handleRelatedTasks(TaskEngagementItem $taskEngagementItem): void
    {
        if ($this->status === TaskStatusEnum::COMPLETED->value) {
            $taskEngagementItem->disableRelatedItems();
            $taskEngagementItem->enableRelatedTasks();
            $taskEngagementItem->completeRelatedItems();

            // Handle complete_other_task_items configuration
            $this->completeOtherTaskItems($taskEngagementItem);
        }
    }

    protected function completeOtherTaskItems(TaskEngagementItem $taskEngagementItem): void
    {
        $config = $taskEngagementItem->item->config ?? [];
        $completeOtherTaskItems = $config['complete_other_task_items'] ?? [];

        if (is_array($completeOtherTaskItems) && ! empty($completeOtherTaskItems)) {
            foreach ($completeOtherTaskItems as $taskItemId) {
                try {
                    $this->completeTaskEngagementItem($taskItemId);
                } catch (Throwable $e) {
                    // Log error but don't stop the process
                    report($e);
                }
            }
        }
    }

    protected function completeTaskEngagementItem(int $taskItemId): void
    {
        $taskListItem = TaskListItem::findOrFail($taskItemId);

        $existingTaskEngagementItem = TaskEngagementItem::fromCompany($this->company)
            ->fromApp($this->app)
            ->where('task_list_item_id', $taskItemId)
            ->where('lead_id', $this->lead->getId())
            ->first();

        if ($existingTaskEngagementItem && $existingTaskEngagementItem->status === 'completed') {
            return; // Already completed
        }

        if (! $existingTaskEngagementItem) {
            $existingTaskEngagementItem = new TaskEngagementItem();
            $existingTaskEngagementItem->task_list_item_id = $taskItemId;
            $existingTaskEngagementItem->lead_id = $this->lead->getId();
            $existingTaskEngagementItem->companies_id = $this->company->getId();
            $existingTaskEngagementItem->apps_id = $this->app->getId();
            $existingTaskEngagementItem->users_id = $this->user->getId();
        }

        // Set engagement_end_id if we have a message with engagement
        if ($this->message) {
            $engagement = $this->getEngagementFromMessage(ActionStatusEnum::SUBMITTED->value);
            if ($engagement) {
                $existingTaskEngagementItem->engagement_end_id = $engagement->getId();
            }
        }

        $existingTaskEngagementItem->status = 'completed';
        $existingTaskEngagementItem->saveOrFail();
    }

    protected function fireWorkflow(TaskEngagementItem $taskEngagementItem): void
    {
        $taskEngagementItem->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $this->app,
                'company' => $this->company,
                'lead' => $this->lead,
                'message' => $this->message,
                'status' => $this->status,
            ]
        );
    }
}
