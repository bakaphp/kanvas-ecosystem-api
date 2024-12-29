<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\CheckList\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\CheckList\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\CheckList\Exceptions\TaskListException;
use Kanvas\ActionEngine\CheckList\Models\TaskEngagementItem;
use Kanvas\ActionEngine\CheckList\Models\TaskListItem;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\Social\Messages\Models\Message;

use function Sentry\captureException;

class UpdateTaskEngagementStatusAction
{
    private ?Engagement $engagement;
    private array $messageData;
    private EngagementMessage $engagementMessage;

    public function __construct(
        private readonly Message $message
    ) {
        $this->messageData = $this->message->getMessageData();
        $this->engagement = Engagement::getByMessage($this->message);
        $this->engagementMessage = EngagementMessage::from($this->message->getMessageData());
    }

    public function execute(): ?TaskEngagementItem
    {
        /**
         * get the checklist items fo the giving list, this is pass in the msg data
         * as a attribute checklistId
         */
        $taskListItem = $this->getTaskListItem();
        if (! $taskListItem) {
            throw new TaskListException('Task List Item not found');
        }

        /**
         * engagement items is the combination of the task list item and the lead
         */
        $taskEngagementItem = $this->getOrCreateTaskEngagementItem($taskListItem);
        if ($this->isCompletedTask($taskEngagementItem)) {
            throw new TaskListException('Task already completed');
        }

        $this->updateTaskStatus($taskEngagementItem);

        if ($this->shouldCompleteOtherTasks($taskListItem)) {
            $this->completeOtherTasks($taskListItem);
        }

        return $taskEngagementItem;
    }

    private function getTaskListItem(): ?TaskListItem
    {
        $checkListId = $this->extractChecklistId();
        if (! $checkListId) {
            return throw new TaskListException('Checklist Id not found in the msg or not configure in the company');
        }

        $companyAction = $this->getCompanyAction();

        return TaskListItem::query()
            ->where('task_list_id', $checkListId)
            ->where('companies_action_id', $companyAction->getId())
            ->first();
    }

    private function getCompanyAction(): CompanyAction
    {
        $verb = $this->messageData['verb'] ?? $this->message->messageType->slug;
        $action = Action::getBySlug($verb, $this->message->app);

        return CompanyAction::getByAction($action, $this->message->companies, $this->message->app);
    }

    private function extractChecklistId(): ?int
    {
        $parentData = $this->message->hasParent() ? $this->message->parent->getMessageData() : [];
        $providedChecklistId = isset($parentData['checklistId'])
            ? (int) $parentData['checklistId']
            : null;

        if ($providedChecklistId > 0) {
            return $providedChecklistId;
        }

        return $this->message->companies->get('default_checklist_id') ?? null;
    }

    private function getOrCreateTaskEngagementItem(TaskListItem $taskListItem): TaskEngagementItem
    {
        $taskEngagementItem = $this->findExistingTaskEngagement($taskListItem);

        if (! $taskEngagementItem) {
            $taskEngagementItem = $this->createNewTaskEngagement($taskListItem);
        }

        return $taskEngagementItem;
    }

    private function findExistingTaskEngagement(TaskListItem $taskListItem): ?TaskEngagementItem
    {
        return TaskEngagementItem::query()
            ->where('task_list_item_id', $taskListItem->getId())
            ->where('lead_id', $this->engagement->leads_id)
            ->first();
    }

    private function createNewTaskEngagement(TaskListItem $taskListItem): TaskEngagementItem
    {
        $taskEngagementItem = new TaskEngagementItem();
        $taskEngagementItem->task_list_item_id = $taskListItem->getId();
        $taskEngagementItem->lead_id = $this->engagement->leads_id;
        $taskEngagementItem->companies_id = $this->message->companies->getId();
        $taskEngagementItem->apps_id = $this->message->app->getId();
        $taskEngagementItem->users_id = $this->message->users_id;

        return $taskEngagementItem;
    }

    private function isCompletedTask(?TaskEngagementItem $taskEngagementItem): bool
    {
        return $taskEngagementItem &&
            $taskEngagementItem->status === TaskStatusEnum::COMPLETED->value;
    }

    private function updateTaskStatus(TaskEngagementItem $taskEngagementItem): void
    {
        if ($this->shouldUpdateToInProgress($taskEngagementItem)) {
            $this->updateToInProgress($taskEngagementItem);
        }

        if ($this->shouldUpdateToCompleted($taskEngagementItem)) {
            $this->updateToCompleted($taskEngagementItem);
        }
    }

    private function shouldUpdateToInProgress(TaskEngagementItem $taskEngagementItem): bool
    {
        return $this->engagementMessage->status === ActionStatusEnum::SENT->value &&
            empty($taskEngagementItem->engagement_start_id);
    }

    private function shouldUpdateToCompleted(TaskEngagementItem $taskEngagementItem): bool
    {
        return $this->engagementMessage->status === ActionStatusEnum::SUBMITTED->value &&
            empty($taskEngagementItem->engagement_end_id);
    }

    private function updateToInProgress(TaskEngagementItem $taskEngagementItem): void
    {
        $taskEngagementItem->status = TaskStatusEnum::IN_PROGRESS->value;
        if ($this->engagement) {
            $taskEngagementItem->engagement_start_id = $this->engagement->getId();
        }
        $taskEngagementItem->saveOrFail();
    }

    private function updateToCompleted(TaskEngagementItem $taskEngagementItem): void
    {
        $taskEngagementItem->status = TaskStatusEnum::COMPLETED->value;
        if ($this->engagement) {
            $taskEngagementItem->engagement_end_id = $this->engagement->getId();
        }
        $taskEngagementItem->saveOrFail();
    }

    private function shouldCompleteOtherTasks(TaskListItem $taskListItem): bool
    {
        $taskItemConfig = $taskListItem->getConfig();

        return isset($taskItemConfig['complete_other_task_items']) &&
            is_array($taskItemConfig['complete_other_task_items']) &&
            ! empty($taskItemConfig['complete_other_task_items']);
    }

    private function completeOtherTasks(TaskListItem $taskListItem): void
    {
        $taskItemConfig = $taskListItem->getConfig();
        $this->completeTasksEngagementItem(
            $taskItemConfig['complete_other_task_items'],
            $this->engagement
        );
    }

    private function completeTasksEngagementItem(array $tasksItems, Engagement $engagement): void
    {
        foreach ($tasksItems as $taskItem) {
            try {
                $taskListItem = TaskListItem::getById($taskItem);
                $taskEngagementItem = $this->findOrCreateTaskEngagement($taskListItem, $engagement);

                if ($this->isCompletedTask($taskEngagementItem)) {
                    continue;
                }

                $taskEngagementItem->status = TaskStatusEnum::COMPLETED->value;
                $taskEngagementItem->saveOrFail();
            } catch (ModelNotFoundException $e) {
                captureException($e);
            }
        }
    }

    private function findOrCreateTaskEngagement(TaskListItem $taskListItem, Engagement $engagement): TaskEngagementItem
    {
        $taskEngagementItem = TaskEngagementItem::query()
            ->where('task_list_item_id', $taskListItem->getId())
            ->where('lead_id', $engagement->leads_id)
            ->fromApp($this->message->app)
            ->notDeleted()
            ->first();

        if (! $taskEngagementItem) {
            return $this->createNewTaskEngagement($taskListItem);
        }

        return $taskEngagementItem;
    }
}
