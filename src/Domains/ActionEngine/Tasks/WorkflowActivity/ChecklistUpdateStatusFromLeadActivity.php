<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\WorkflowActivity;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Tasks\Actions\ChangeTaskEngagementItemStatusAction;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ChecklistUpdateStatusFromLeadActivity extends KanvasActivity
{
    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $taskItemId = $lead->get('checklist_upload');

                if (! $taskItemId) {
                    return [
                        'message' => 'No task item id found',
                        'result' => false,
                    ];
                }

                $taskListItem = TaskListItem::findOrFail($taskItemId);

                $existingItem = TaskEngagementItem::where([
                    'task_list_item_id' => $taskListItem->getId(),
                    'lead_id' => $lead->getId(),
                    'apps_id' => $app->getId(),
                    'is_deleted' => 0,
                ])->first();

                if ($existingItem && $existingItem->status === 'completed') {
                    return [
                        'message' => 'Checklist status already completed',
                        'result' => true,
                    ];
                }

                $verb = 'upload-document';
                $uuid = new Str()->uuid();
                $messageData = [
                    'verb' => $verb,
                    'engagement_status' => 'submitted',
                    'message' => [
                        'leads_uuid' => $lead->uuid,
                        'status' => 'submitted',
                        'source' => 'checklist',
                        'text' => 'User uploaded a document ' . $taskListItem->name,
                        'visitor_id' => $uuid,
                    ],
                    'files' => [],
                ];

                $messageTypeDto = MessageTypeInput::from([
                    'apps_id' => $app->getId(),
                    'name' => $verb,
                    'verb' => $verb,
                ]);
                $messageType = new CreateMessageTypeAction($messageTypeDto)->execute();

                $newMessage = new CreateMessageAction(
                    new MessageInput(
                        app: $app,
                        company: $lead->company,
                        user: $lead->user,
                        type: $messageType,
                        message: $messageData
                    ),
                    SystemModulesRepository::getByModelName(Lead::class, $app),
                    $lead->getId()
                )->execute();

                $status = 'submitted';
                Engagement::firstOrCreate([
                    'companies_id' => $lead->company->getId(),
                    'apps_id' => $app->getId(),
                    'users_id' => $lead->user->getId(),
                    'leads_id' => $lead->getId(),
                    'people_id' => $lead->people->getId(),
                    'companies_actions_id' => $taskListItem->companyAction->getId(),
                    'message_id' => $newMessage->getId(),
                    'slug' => $verb,
                    'entity_uuid' => (string) $uuid,
                    'pipelines_stages_id' => $this->getStageId($taskListItem, $status),
                ]);

                /** @var \Kanvas\Users\Models\Users $leadUser */
                $leadUser = $lead->user;
                /** @var \Kanvas\Companies\Models\Companies $leadCompany */
                $leadCompany = $lead->company;

                $taskEngagementItem = new ChangeTaskEngagementItemStatusAction(
                    taskListItem: $taskListItem,
                    lead: $lead,
                    status: 'completed',
                    user: $leadUser,
                    app: $app,
                    company: $leadCompany,
                    message: $newMessage,
                )->execute();

                $lead->del('checklist_upload');

                return [
                    'message' => 'Checklist status updated successfully',
                    'result' => true,
                    'task_engagement_item_id' => $taskEngagementItem->getId(),
                ];
            },
            company: $lead->company
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
