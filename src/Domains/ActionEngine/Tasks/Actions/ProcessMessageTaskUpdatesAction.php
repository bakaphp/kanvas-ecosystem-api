<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

class ProcessMessageTaskUpdatesAction
{
    public function __construct(
        protected Message $message,
        protected Lead $lead,
        protected ?Users $user = null,
    ) {
        $this->user = $this->user ?? $message->user;
    }

    public function execute(): array
    {
        $messageData = $this->message->getMessage();
        $verb = $messageData['verb'] ?? null;
        $status = $messageData['status'] ?? null;

        if (! $verb || ! $status) {
            throw new InvalidArgumentException('Verb and status are required to set task engagement status.');
        }

        $results = [];

        // Handle regular task items
        $taskListItems = $this->findTaskListItems($messageData);

        if ($taskListItems->count() === 0) {
            return [
                'success' => false,
                'message' => 'No task list items found for the given verb and checklist ID. ',
            ];
        }

        foreach ($taskListItems->get() as $taskListItem) {
            $result = $this->processTaskListItem($taskListItem, $messageData);
            if ($result) {
                $results[] = $result;
            }
        }

        // Handle sign docs files
        $signDocsResults = $this->handleSignDocsFiles($messageData);
        $results = array_merge($results, $signDocsResults);

        return [
            'success' => ! empty($results),
            'results' => $results,
            'message' => 'Task engagement status updated',
        ];
    }

    protected function findTaskListItems(array $messageData): Builder
    {
        $verb = $messageData['verb'];
        $data = $messageData['data'] ?? [];

        $action = Action::where('slug', $verb)->firstOrFail();
        $companyAction = CompanyAction::getByAction($action, $this->lead->company, $this->lead->app);

        $checkListId = $this->getCheckListId($messageData);

        $query = TaskListItem::where('companies_action_id', $companyAction->getId())
            ->where('is_deleted', 0);

        if ($checkListId) {
            $query->where('task_list_id', $checkListId);
        }

        // Handle specific data type conditions
        return $this->applyDataConditions($query, $data);
    }

    protected function getCheckListId(array $messageData): ?int
    {
        $verb = $messageData['verb'];
        $data = $messageData['data'] ?? [];

        // Special handling for certain verbs
        if (in_array($verb, ['sold-car-verification', 'payoff-verification', 'mileage-confirmation','bdc-needs-assessment'])) {
            return $messageData['checkListId'] ?? $this->lead->company->get('default_checklist_id');
        }

        // Check parent message for checklist ID
        $parentData = $this->message->parent ? $this->message->parent->getMessage() : [];
        $parentChecklistId = $parentData['checkListId'] ?? null;

        if ($parentChecklistId && (int) $parentChecklistId > 0) {
            return (int) $parentChecklistId;
        }

        return $this->lead->company->get('default_checklist_id');
    }

    protected function applyDataConditions(Builder $query, array $data): Builder
    {
        // Drivers License condition
        if (isset($data[3]) && ($data[3]['type']['name'] ?? '') === 'Drivers License') {
            $query->whereJsonContains('config->id', 3);
        }

        // Insurance card condition
        if (isset($data[10]) && ($data[10]['type']['name'] ?? '') === 'Insurance card') {
            $query->whereJsonContains('config->id', 10);
        }

        // Company-specific sign docs conditions
        $this->applySignDocsConditions($query, $data);

        return $query;
    }

    protected function applySignDocsConditions(Builder $query, array $data): void
    {
        $messageData = $this->message->getMessage();
        $isSignDocs = $messageData['verb'] === 'esign-docs';

        if (! $isSignDocs) {
            return;
        }

        $filename = $data['documentForms'][0]['filename'] ?? null;
        $companyId = $this->lead->company->getId();

        $conditionsMap = [
            'privacy-disclosure.pdf' => [
                8412 => 678837,
                8485 => 895776,
            ],
            'insurance-information.pdf' => [
                8485 => 895775,
            ],
            'working-disclosure-form.pdf' => [
                8412 => 678838,
            ],
        ];

        if ($filename && isset($conditionsMap[$filename][$companyId])) {
            $configId = $conditionsMap[$filename][$companyId];
            $query->whereJsonContains('config->id', $configId);
        }
    }

    protected function handleSignDocsFiles(array $messageData): array
    {
        $data = $messageData['data'] ?? [];
        $documentForms = $data['documentForms'] ?? [];

        if (empty($documentForms)) {
            return [];
        }

        $results = [];
        $engagement = Engagement::where('message_id', $this->message->getId())->first();

        foreach ($documentForms as $document) {
            $filename = $document['filename'] ?? null;
            if (! $filename) {
                continue;
            }

            $taskListItem = $this->findTaskListItemByFilename($filename);
            if ($taskListItem && $messageData['status'] === 'submitted') {
                $result = $this->processTaskListItem($taskListItem, $messageData, $engagement);
                if ($result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    protected function findTaskListItemByFilename(string $filename): ?TaskListItem
    {
        $checkListId = $this->getCheckListId($this->message->getMessage());
        $verb = $this->message->getMessage()['verb'];

        try {
            $action = Action::where('slug', $verb)->firstOrFail();
            $companyAction = CompanyAction::getByAction($action, $this->lead->company, $this->lead->app);
        } catch (Throwable $e) {
            return null;
        }

        return TaskListItem::where(function ($query) use ($filename) {
            $query->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(config, "$.file_name")) = ?', [$filename])
                  ->orWhereRaw('JSON_CONTAINS(JSON_EXTRACT(config, "$.file_name"), JSON_QUOTE(?))', [$filename]);
        })
        ->where('is_deleted', 0)
        ->where('companies_action_id', $companyAction->getId())
        ->where('task_list_id', $checkListId)
        ->first();
    }

    protected function processTaskListItem(
        TaskListItem $taskListItem,
        array $messageData,
        ?Engagement $engagement = null
    ): ?array {
        $status = $messageData['status'];

        // Map message status to task status
        $taskStatus = $this->mapMessageStatusToTaskStatus($status);

        if (! $taskStatus) {
            return null;
        }

        $changeStatusAction = new ChangeTaskEngagementItemStatusAction(
            taskListItem: $taskListItem,
            lead: $this->lead,
            status: $taskStatus,
            user: $this->user,
            app: $this->lead->app,
            company: $this->lead->company,
            message: $this->message
        );
        $taskEngagementItem = $changeStatusAction->execute();

        return [
            'task_list_item_id' => $taskListItem->getId(),
            'status' => $taskStatus,
            'engagement_item_id' => $taskEngagementItem->id,
        ];
    }

    protected function mapMessageStatusToTaskStatus(string $messageStatus): ?string
    {
        return match ($messageStatus) {
            'sent' => 'in_progress',
            'submitted' => 'completed',
            default => null,
        };
    }
}
