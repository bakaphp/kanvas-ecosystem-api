<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Tasks\Models\TaskEngagementItem;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class AttachFileToChecklistItemActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    /**
     * $entity <TaskEngagementItem>
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $config = $entity->config;

        $peopleId = $config['people_id'] ?? null;
        $fileUpload = $config['file_upload'] ?? null;
        $peopleFromLead = false;

        if ($peopleId === null && $fileUpload === null) {
            return [
                'result' => false,
                'message' => 'No file or people id provided',
            ];
        }

        $systemModuleIds = [
            SystemModulesRepository::getByModelName(People::class, $app)->getId(),
            SystemModulesRepository::getByModelName(SystemModules::getLegacyNamespace(People::class), $app)->getId(),
        ];
        $lead = $entity->lead;

        if ($peopleId === null) {
            //this is using the lead people Id
            $peopleId = $lead->people->id;
            $peopleFromLead = true;
        }

        try {
            $people = People::getById($peopleId, $app);
        } catch (ModelNotFoundException $e) {
            return [
                'result' => false,
                'message' => 'People not found with id ' . $peopleId,
            ];
        }

        if (! $peopleFromLead) {
            $latestFile = FilesystemEntities::query()
                ->where('entity_id', $people->getId())
                ->whereIn('system_modules_id', $systemModuleIds)
                ->where('companies_id', $lead->companies_id)
                ->latest()
                ->first();
        } else {
            $systemModuleIds = [
                SystemModulesRepository::getByModelName(Lead::class, $app)->getId(),
                SystemModulesRepository::getByModelName(SystemModules::getLegacyNamespace(Lead::class), $app)->getId(),
            ];
            $latestFile = FilesystemEntities::query()
                ->where('entity_id', $lead->getId())
                ->whereIn('system_modules_id', $systemModuleIds)
                ->where('companies_id', $lead->companies_id)
                ->where('field_name', 'LIKE', '%Drivers_License%')
                ->latest()
                ->first();
        }

        if ($latestFile === null) {
            return [
                'result' => false,
                'people' => $people->toArray(),
                'lead' => $lead->toArray(),
                'system_modules_id' => $systemModuleIds,
                'message' => 'No file found for checklist item' . $entity->getId(),
            ];
        }

        $engagementMessage = new EngagementMessage(
            data: [],
            text: ConfigurationEnum::GET_DOCS->value,
            verb: ConfigurationEnum::GET_DOCS->value,
            hashtagVisited: ConfigurationEnum::GET_DOCS->value,
            actionLink: 'http://nolink.com',
            source: 'workflow',
            linkPreview: 'http://nolink.com',
            engagementStatus: ActionStatusEnum::SUBMITTED->value,
            visitorId: Str::uuid()->toString(),
            status: ActionStatusEnum::SUBMITTED->value,
        );

        $messageInput = [
            'message' => $engagementMessage->toArray(),
            'reactions_count' => 0,
            'comments_count' => 0,
            'total_liked' => 0,
            'total_disliked' => 0,
            'total_saved' => 0,
            'total_shared' => 0,
            'ip_address' => '127.0.0.1',
        ];

        $createMessage = new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $entity->user,
                MessageType::fromApp($app)->where('verb', ConfigurationEnum::GET_DOCS->value)->firstOrFail(),
                $lead->company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        );

        $message = $createMessage->execute();
        $message->addFile($latestFile->filesystem, $latestFile->field_name);

        $submittedStage = $entity->item->companyAction->pipeline->stages()
        ->where('slug', ActionStatusEnum::SUBMITTED->value)
        ->firstOrFail();

        $engagement = Engagement::firstOrCreate([
            'companies_id' => $entity->companies_id,
            'apps_id' => $app->getId(),
            'users_id' => $entity->users_id,
            'leads_id' => $lead->getId(),
            'people_id' => $people->getId(),
            'companies_actions_id' => $entity->item->companyAction->getId(),
            'message_id' => $message->getId(),
            'slug' => ConfigurationEnum::GET_DOCS->value,
            'entity_uuid' => $lead->uuid,
            'pipelines_stages_id' => $submittedStage->getId(),
        ]);
        $entity->engagement_end_id = $engagement->getId();
        $entity->saveOrFail();

        return [
            'message' => 'File attached to checklist item',
            'result' => true,
            'engagement' => $engagement->toArray(),
            'file' => $latestFile->toArray(),
            'entity' => $entity->toArray(),
        ];
    }
}
