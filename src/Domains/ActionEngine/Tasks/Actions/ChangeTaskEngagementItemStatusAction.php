<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Enums\TaskStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\ActionEngine\Tasks\Traits\ExtractsSubmittedDocumentTypes;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Single source of truth for changing a TaskEngagementItem's status.
 *
 * Every entry point that flips a task's status MUST go through this action —
 * the GraphQL mutation, message processors, workflow activities, and connector
 * activities all delegate here. Do not inline `$item->status = ...` + save in
 * new code; the side effects below will silently not run.
 *
 * Side effects, gated by the resulting status:
 * - completed:
 *   - disable/enable/complete related items (via per-item `config` keys
 *     `other_items_to_disable`, `task_item_to_enable`, `complete_other_task_items`)
 *   - sibling fan-out across other checklists when the company flag
 *     `checklist_auto_complete_siblings` is set, driven by the attached
 *     `Message`'s verb: `ActionEnum::GET_DOCS` matches siblings by `config.id`
 *     against `data.*.type.id`; `ActionEnum::ESIGN_DOCS` matches siblings by
 *     `config.file_name` against `data.documentForms[].filename`. Falls back to
 *     name + `companies_action_id` match when there's no message.
 * - no_applicable:
 *   - disable related items (`other_items_to_disable`)
 */
class ChangeTaskEngagementItemStatusAction
{
    use ExtractsSubmittedDocumentTypes;

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
        return TaskEngagementItem::firstOrCreate(
            [
                'task_list_item_id' => $this->taskListItem->getId(),
                'lead_id' => $this->lead->getId(),
            ],
            [
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
            ],
        );
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
        if ($this->status === TaskStatusEnum::NO_APPLICABLE->value) {
            $taskEngagementItem->disableRelatedItems();
        }

        if ($this->status === TaskStatusEnum::COMPLETED->value) {
            $taskEngagementItem->disableRelatedItems();
            $taskEngagementItem->enableRelatedTasks();
            $taskEngagementItem->completeRelatedItems();

            // Handle complete_other_task_items configuration
            $this->completeOtherTaskItems($taskEngagementItem);

            // Hack: complete all sibling items in the same checklist for this company
            if ($this->company->get('checklist_auto_complete_siblings')) {
                $this->completeSiblingChecklistItems();
            }
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
        TaskListItem::findOrFail($taskItemId);

        $engagement = $this->message
            ? $this->getEngagementFromMessage(ActionStatusEnum::SUBMITTED->value)
            : null;

        $existingTaskEngagementItem = TaskEngagementItem::firstOrCreate(
            [
                'task_list_item_id' => $taskItemId,
                'lead_id' => $this->lead->getId(),
            ],
            [
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'status' => TaskStatusEnum::COMPLETED->value,
                'engagement_end_id' => $engagement?->getId(),
            ],
        );

        if ($existingTaskEngagementItem->status === TaskStatusEnum::COMPLETED->value) {
            return;
        }

        if ($engagement) {
            $existingTaskEngagementItem->engagement_end_id = $engagement->getId();
        }

        $existingTaskEngagementItem->status = TaskStatusEnum::COMPLETED->value;
        $existingTaskEngagementItem->saveOrFail();
    }

    /**
     * Hack: when an item is completed, fan out the completion to matching
     * siblings across other checklists for this company/app.
     *
     * Matching is driven by the message payload when present:
     * - verb=ActionEnum::GET_DOCS: collect `data.*.type.id` from the message,
     *   gate on the current item's `config.id` being in that set, then match
     *   siblings by `config.id`.
     * - verb=ActionEnum::ESIGN_DOCS: collect `data.documentForms[].filename`,
     *   gate on the current item's `config.file_name`, then match siblings by
     *   file_name.
     * - otherwise: fall back to name + companies_action_id match.
     */
    protected function completeSiblingChecklistItems(): void
    {
        $matchingItems = $this->findSiblingChecklistItems();

        foreach ($matchingItems as $matchingItem) {
            try {
                $existing = TaskEngagementItem::firstOrCreate(
                    [
                        'task_list_item_id' => $matchingItem->getId(),
                        'lead_id' => $this->lead->getId(),
                    ],
                    [
                        'companies_id' => $this->company->getId(),
                        'apps_id' => $this->app->getId(),
                        'users_id' => $this->user->getId(),
                        'status' => TaskStatusEnum::COMPLETED->value,
                    ],
                );

                if ($existing->status === TaskStatusEnum::COMPLETED->value) {
                    continue;
                }

                $existing->status = TaskStatusEnum::COMPLETED->value;
                $existing->saveOrFail();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function findSiblingChecklistItems(): iterable
    {
        $verb = $this->message?->getMessage()['verb'] ?? null;

        if ($verb === ActionEnum::GET_DOCS->value) {
            return $this->findSiblingsByGetDocs();
        }

        if ($verb === ActionEnum::ESIGN_DOCS->value) {
            return $this->findSiblingsBySignDocs();
        }

        return $this->findSiblingsByName();
    }

    protected function findSiblingsByGetDocs(): iterable
    {
        $submittedIds = $this->extractGetDocsDocumentTypeIds(
            $this->message?->getMessage()['data'] ?? []
        );

        if (empty($submittedIds)) {
            return [];
        }

        $currentConfigId = $this->taskListItem->config['id'] ?? null;
        if ($currentConfigId === null || ! in_array((int) $currentConfigId, $submittedIds, true)) {
            return [];
        }

        return $this->baseSiblingQuery()
            ->where(function (Builder $query) use ($submittedIds): void {
                foreach ($submittedIds as $id) {
                    $query->orWhereJsonContains('company_task_list_items.config->id', $id);
                }
            })
            ->select('company_task_list_items.*')
            ->get();
    }

    /**
     * @return iterable<TaskListItem>
     */
    protected function findSiblingsBySignDocs(): iterable
    {
        $submittedFilenames = $this->extractSignDocsFilenames();

        if (empty($submittedFilenames)) {
            return [];
        }

        $currentFilename = $this->taskListItem->config['file_name'] ?? null;
        if ($currentFilename === null || ! in_array($currentFilename, $submittedFilenames, true)) {
            return [];
        }

        return $this->baseSiblingQuery()
            ->where(function (Builder $query) use ($submittedFilenames): void {
                foreach ($submittedFilenames as $filename) {
                    $query->orWhereRaw(
                        'JSON_UNQUOTE(JSON_EXTRACT(company_task_list_items.config, "$.file_name")) = ?',
                        [$filename]
                    )->orWhereRaw(
                        'JSON_CONTAINS(JSON_EXTRACT(company_task_list_items.config, "$.file_name"), JSON_QUOTE(?))',
                        [$filename]
                    );
                }
            })
            ->select('company_task_list_items.*')
            ->get();
    }

    /**
     * @return iterable<TaskListItem>
     */
    protected function findSiblingsByName(): iterable
    {
        return $this->baseSiblingQuery()
            ->where('company_task_list_items.name', $this->taskListItem->name)
            ->where('company_task_list_items.companies_action_id', $this->taskListItem->companies_action_id)
            ->select('company_task_list_items.*')
            ->get();
    }

    protected function baseSiblingQuery(): Builder
    {
        return TaskListItem::join('company_task_list', 'company_task_list.id', '=', 'company_task_list_items.task_list_id')
            ->where('company_task_list.companies_id', $this->company->getId())
            ->where('company_task_list.apps_id', $this->app->getId())
            ->where('company_task_list.is_deleted', 0)
            ->where('company_task_list_items.id', '!=', $this->taskListItem->getId())
            ->where('company_task_list_items.is_deleted', 0);
    }

    /**
     * @return array<int, string>
     */
    protected function extractSignDocsFilenames(): array
    {
        $documentForms = $this->message?->getMessage()['data']['documentForms'] ?? [];

        $filenames = [];
        foreach ($documentForms as $document) {
            $filename = $document['filename'] ?? null;
            if (is_string($filename) && $filename !== '') {
                $filenames[] = $filename;
            }
        }

        return array_values(array_unique($filenames));
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
