<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Repositories\PipelineStageRepository;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\ActionEngine\Tasks\Traits\ExtractsSubmittedDocumentTypes;
use Kanvas\ActionEngine\Tasks\Traits\IdentifiesCoBuyerTaskItems;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

class ProcessMessageTaskUpdatesAction
{
    use ExtractsSubmittedDocumentTypes;
    use IdentifiesCoBuyerTaskItems;

    /** Verbs whose task items split into main-buyer vs co-buyer; add new ones here. */
    protected const COBUYER_AWARE_VERBS = [
        ActionEnum::ID_VERIFICATION->value,
    ];

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

        // For esign-docs, only use filename-based matching to avoid processing all esign tasks
        if ($verb === ActionEnum::ESIGN_DOCS->value) {
            $signDocsResults = $this->handleSignDocsFiles($messageData);

            return [
                'success' => ! empty($signDocsResults),
                'results' => $signDocsResults,
                'message' => 'Task engagement status updated',
            ];
        }

        // Handle regular task items
        $taskListItems = $this->findTaskListItems($messageData);

        if ($taskListItems->count() === 0) {
            return [
                'success' => false,
                'message' => 'No task list items found for the given verb and checklist ID.',
            ];
        }

        foreach ($taskListItems->get() as $taskListItem) {
            $result = $this->processTaskListItem($taskListItem, $messageData);
            if ($result) {
                $results[] = $result;
            }
        }

        return [
            'success' => ! empty($results),
            'results' => $results,
            'message' => 'Task engagement status updated',
        ];
    }

    protected function findTaskListItems(array $messageData): Builder
    {
        $verb = $messageData['verb'];

        $action = Action::where('slug', $verb)->firstOrFail();
        $companyAction = CompanyAction::getByAction($action, $this->lead->company, $this->lead->app);

        $checkListId = $this->getCheckListId($messageData);

        $query = TaskListItem::where('companies_action_id', $companyAction->getId())
            ->where('is_deleted', 0);

        if ($checkListId) {
            $query->where('task_list_id', $checkListId);
        }

        $this->applyPersonRoleScope($query, $messageData);

        return $this->applyDataConditions($query, $messageData);
    }

    /**
     * Main-buyer and co-buyer tasks share the same company action, so key off the verified
     * person instead: `contact_uuid` differs from the lead's main people for a co-buyer, and
     * co-buyer items carry a `cobuyer-picker` config step. Scoped mutually exclusively — a
     * co-buyer completes only cobuyer-picker items, the main buyer only the rest.
     */
    protected function applyPersonRoleScope(Builder $query, array $messageData): Builder
    {
        if (! in_array($messageData['verb'] ?? null, self::COBUYER_AWARE_VERBS, true)) {
            return $query;
        }

        $contactUuid = $messageData['contact_uuid'] ?? null;
        $mainPeopleUuid = $this->lead->people->uuid ?? null;

        $isCoBuyer = $contactUuid !== null
            && $mainPeopleUuid !== null
            && $contactUuid !== $mainPeopleUuid;

        return $query->whereRaw($this->coBuyerConfigPredicate('config', $isCoBuyer));
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

    /**
     * Narrow the task-item query to the documents the message actually submitted.
     *
     * A get-docs message carries one entry per document type (`data` keyed by
     * type id, each with `type.id`), and each document type maps to a task item
     * through its `config.id`. Without this scoping a single uploaded document
     * completes every get-docs task item under the company action.
     */
    protected function applyDataConditions(Builder $query, array $messageData): Builder
    {
        if (($messageData['verb'] ?? null) !== ActionEnum::GET_DOCS->value) {
            return $query;
        }

        $submittedDocumentTypeIds = $this->extractGetDocsDocumentTypeIds($messageData['data'] ?? []);

        if (empty($submittedDocumentTypeIds)) {
            // Nothing recognizable was submitted — complete nothing rather than everything.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $documentTypeQuery) use ($submittedDocumentTypeIds): void {
            foreach ($submittedDocumentTypeIds as $documentTypeId) {
                $documentTypeQuery->orWhereJsonContains('config->id', $documentTypeId);
            }
        });
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

        $this->ensureEngagement($taskListItem, $messageData);

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

    /**
     * Ensure an Engagement exists for the (message, companyAction) pair so that
     * downstream task-engagement bookkeeping can link start/end engagement ids.
     * Matched by the natural uniqueness keys; everything else falls through as
     * defaults if a new row is created.
     */
    protected function ensureEngagement(TaskListItem $taskListItem, array $messageData): Engagement
    {
        $companyAction = $taskListItem->companyAction;
        $verb = (string) ($messageData['verb'] ?? '');
        $status = (string) ($messageData['status'] ?? '');

        return Engagement::firstOrCreate(
            [
                'message_id' => $this->message->getId(),
                'companies_actions_id' => $companyAction->getId(),
                'slug' => $verb,
            ],
            [
                'companies_id' => $this->lead->company->getId(),
                'apps_id' => $this->lead->app->getId(),
                'users_id' => $this->user->getId(),
                'leads_id' => $this->lead->getId(),
                'people_id' => $this->lead->people->getId(),
                'entity_uuid' => $this->message->uuid,
                'pipelines_stages_id' => $this->getStageId($taskListItem, $status),
            ]
        );
    }

    protected function getStageId(TaskListItem $taskListItem, string $status): int
    {
        return PipelineStageRepository::getForTaskListItem(
            $taskListItem,
            $status
        )->getId();
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
