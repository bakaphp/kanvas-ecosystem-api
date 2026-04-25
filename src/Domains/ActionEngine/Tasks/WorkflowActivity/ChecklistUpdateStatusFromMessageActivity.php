<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\WorkflowActivity;

use Baka\Contracts\AppInterface;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class ChecklistUpdateStatusFromMessageActivity extends KanvasActivity
{
    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Message $message, AppInterface $app) use ($params) {
                /** @var array<string, mixed> $messagePayload */
                $messagePayload = is_array($message->message) ? $message->message : [];

                $payloadVerb = $messagePayload['verb'] ?? null;
                $actionSlug = $message->messageType?->verb
                    ?? (is_string($payloadVerb) ? $payloadVerb : null);

                if (! is_string($actionSlug) || $actionSlug === '') {
                    return [
                        'message' => 'Could not resolve action slug from message verb',
                        'result' => false,
                    ];
                }

                $expectedStatus = $params['expected_message_status'] ?? 'submitted';
                $messageStatus = $messagePayload['status'] ?? $messagePayload['engagement_status'] ?? null;

                if ($expectedStatus !== null && $expectedStatus !== '' && $messageStatus !== $expectedStatus) {
                    return [
                        'message' => "Message status '" . (string) $messageStatus . "' does not match expected '" . (string) $expectedStatus . "'",
                        'result' => false,
                    ];
                }

                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return [
                        'message' => 'Message is not linked to a Lead entity',
                        'result' => false,
                    ];
                }

                /** @var Companies $company */
                $company = $message->company;

                $taskListItems = TaskListItem::join(
                    'companies_actions',
                    'companies_actions.id',
                    '=',
                    'company_task_list_items.companies_action_id'
                )
                    ->join('actions', 'actions.id', '=', 'companies_actions.actions_id')
                    ->where('actions.slug', $actionSlug)
                    ->where('actions.is_deleted', 0)
                    ->where('companies_actions.companies_id', $company->getId())
                    ->where('companies_actions.apps_id', $app->getId())
                    ->where('companies_actions.is_deleted', 0)
                    ->where('company_task_list_items.is_deleted', 0)
                    ->select('company_task_list_items.*')
                    ->get();

                if ($taskListItems->isEmpty()) {
                    return [
                        'message' => "No task list items found for action slug '{$actionSlug}'",
                        'result' => false,
                    ];
                }

                $completed = [];
                $skipped = [];
                $engagementsByCompanyAction = [];

                /** @var Users $messageUser */
                $messageUser = $message->user;

                foreach ($taskListItems as $taskListItem) {
                    try {
                        $existingItem = TaskEngagementItem::where([
                            'task_list_item_id' => $taskListItem->getId(),
                            'lead_id' => $lead->getId(),
                            'apps_id' => $app->getId(),
                            'is_deleted' => 0,
                        ])->first();

                        if ($existingItem && $existingItem->status === 'completed') {
                            $skipped[] = [
                                'task_list_item_id' => $taskListItem->getId(),
                                'reason' => 'already_completed',
                            ];

                            continue;
                        }

                        $companyActionId = $taskListItem->companyAction->getId();

                        if (! isset($engagementsByCompanyAction[$companyActionId])) {
                            $engagementsByCompanyAction[$companyActionId] = Engagement::firstOrCreate([
                                'companies_id' => $company->getId(),
                                'apps_id' => $app->getId(),
                                'users_id' => $messageUser->getId(),
                                'leads_id' => $lead->getId(),
                                'people_id' => $lead->people->getId(),
                                'companies_actions_id' => $companyActionId,
                                'message_id' => $message->getId(),
                                'slug' => $actionSlug,
                                'entity_uuid' => $message->uuid,
                                'pipelines_stages_id' => $this->getStageId($taskListItem, (string) ($messageStatus ?? 'submitted')),
                            ]);
                        }

                        $taskEngagementItem = new ChangeTaskEngagementItemStatusAction(
                            taskListItem: $taskListItem,
                            lead: $lead,
                            status: 'completed',
                            user: $messageUser,
                            app: $app,
                            company: $company,
                            message: $message,
                        )->execute();

                        $completed[] = [
                            'task_list_item_id' => $taskListItem->getId(),
                            'task_engagement_item_id' => $taskEngagementItem->getId(),
                        ];
                    } catch (Throwable $e) {
                        report($e);
                        $skipped[] = [
                            'task_list_item_id' => $taskListItem->getId(),
                            'reason' => 'error',
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                return [
                    'message' => 'Checklist items updated from message',
                    'result' => count($completed) > 0,
                    'action_slug' => $actionSlug,
                    'message_id' => $message->getId(),
                    'lead_id' => $lead->getId(),
                    'completed' => $completed,
                    'skipped' => $skipped,
                ];
            },
            company: $message->company
        );
    }

    private function getStageId(TaskListItem $taskListItem, string $status): int
    {
        if ($taskListItem->companyAction->pipeline) {
            $stage = $taskListItem->companyAction->pipeline->stages()
                ->where('slug', $status)
                ->first();

            if ($stage) {
                return $stage->getId();
            }
        }

        return 1;
    }
}
