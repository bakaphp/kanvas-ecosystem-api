<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\WorkflowActivity;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
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
                    $results = ['message' => 'No task item id found'];

                    return [
                        'message' => $results['message'],
                        'result' => false,
                    ];
                }

                $taskListItem = TaskListItem::findOrFail($taskItemId);

                $taskEngagementItem = TaskEngagementItem::where([
                    'task_list_item_id' => $taskListItem->getId(),
                    'lead_id' => $lead->getId(),
                    'apps_id' => $lead->app->getId(),
                    'is_deleted' => 0,
                ])->first();

                if ($taskEngagementItem && $taskEngagementItem->status === 'completed') {
                    return [
                        'message' => 'Checklist status already completed',
                        'result' => true,
                    ];
                }

                $random = new Str();
                $uuid = $random->uuid();
                $verb = 'upload-document';
                $status = 'submitted';
                $messageData = [
                    'verb' => $verb,
                    'engagement_status' => $status,
                    'message' => [
                        'leads_uuid' => $lead->uuid,
                        'status' => $status,
                        'source' => 'checklist',
                        'text' => 'User uploaded a document ' . $taskListItem->name,
                        'visitor_id' => $uuid,
                    ],
                    'files' => [],
                ];

                // Create message type if it doesn't exist

                $messageTypeDto = MessageTypeInput::from([
                    'apps_id' => $app->getId(),
                    'name' => $verb,
                    'verb' => $verb,
                ]);
                $messageType = (new CreateMessageTypeAction($messageTypeDto))->execute();

                // Create the message using Laravel's CreateMessageAction
                $createMessage = new CreateMessageAction(
                    new MessageInput(
                        app: $app,
                        company: $lead->companies,
                        user: $lead->users,
                        type: $messageType,
                        message: $messageData
                    ),
                    SystemModulesRepository::getByModelName(Lead::class, $app),
                    $lead->getId()
                );

                $newMessage = $createMessage->execute();

                // Create engagement using Laravel's Engagement model
                $engagement = Engagement::firstOrCreate([
                    'companies_id' => $lead->companies->getId(),
                    'apps_id' => $lead->app->getId(),
                    'users_id' => $lead->users->getId(),
                    'leads_id' => $lead->getId(),
                    'people_id' => $lead->people->getId(),
                    'companies_actions_id' => $taskListItem->companyAction->getId(),
                    'message_id' => $newMessage->getId(),
                    'slug' => $verb,
                    'entity_uuid' => (string) $uuid,
                    'pipelines_stages_id' => $this->getStageId($taskListItem->companyAction, $status),
                ]);

                // Handle task engagement item
                if (! $taskEngagementItem) {
                    $taskEngagementItem = new TaskEngagementItem();
                    $taskEngagementItem->task_list_item_id = $taskListItem->getId();
                    $taskEngagementItem->lead_id = $lead->getId();
                    $taskEngagementItem->companies_id = $lead->companies->getId();
                    $taskEngagementItem->apps_id = $lead->app->getId();
                    $taskEngagementItem->users_id = $lead->users->getId();
                }

                $taskEngagementItem->engagement_start_id = $engagement->getId();
                $taskEngagementItem->engagement_end_id = $engagement->getId();
                $taskEngagementItem->status = 'completed';
                $taskEngagementItem->saveOrFail();

                $lead->del('checklist_upload');

                return [
                    'message' => 'Checklist status updated successfully',
                    'result' => true,
                    'engagement_id' => $engagement->getId(),
                ];
            },
            company: $lead->company
        );
    }

    private function getStageId(CompanyAction $companyAction, string $status): int
    {
        // Get the appropriate stage ID based on the status
        if ($companyAction->pipeline) {
            $stage = $companyAction->pipeline->stages()
                ->where('slug', $status)
                ->first();

            if ($stage) {
                return $stage->getId();
            }
        }

        // Fallback - you might want to handle this differently
        return 1;
    }
}
