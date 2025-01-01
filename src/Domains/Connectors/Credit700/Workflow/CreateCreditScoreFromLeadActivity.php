<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Workflow;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Connectors\Credit700\Support\Setup;
use Kanvas\Enums\AppEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Services\DistributionMessageService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\KanvasActivity;

class CreateCreditScoreFromLeadActivity extends KanvasActivity
{
    /**
     * @param Model<Lead> $lead
     */
    public function execute(Model $lead, Apps $app, array $params): array
    {
        $this->overWriteAppPermissionService($app);

        if (! $this->validateParams($params)) {
            return $this->errorResponse('Invalid pull parameter', $lead);
        }

        $setup = new Setup($app);
        $setup->run();

        $engagement = $this->getEngagement($lead);
        $messageData = $this->extractMessageData($engagement?->message);

        if (! $messageData) {
            return $this->errorResponse('Message data not found', $lead);
        }

        $creditApplicant = $this->processCreditScore($messageData, $lead, $app, $params);

        if (empty($creditApplicant['iframe_url'])) {
            return $this->errorResponse('Credit score not found', $lead, $creditApplicant);
        }

        $parentMessage = $this->createParentMessage(
            $creditApplicant,
            $lead,
            $app,
            $engagement->message
        );
        $childMessage = $this->createChildMessage(
            $creditApplicant,
            $lead,
            $app,
            $engagement->message,
            $parentMessage
        );

        $this->distributeMessages($lead, $app, $parentMessage, $childMessage);
        $this->createEngagements($lead, $app, $parentMessage, $childMessage, $engagement->message);

        return [
            'scores' => $creditApplicant['scores'],
            'iframe_url' => $creditApplicant['iframe_url'],
            'iframe_url_signed' => $creditApplicant['iframe_url_signed'],
            'pdf' => ! empty($creditApplicant['pdf']) && $creditApplicant['pdf'] instanceof Filesystem ? $creditApplicant['pdf']->url : null,
            'message_id' => $parentMessage->getId(),
            'message' => 'Credit score created successfully',
            'lead_id' => $lead->getId(),
        ];
    }

    protected function validateParams(array $params): bool
    {
        return isset($params['pull']) && $params['pull'] === '700credit';
    }

    protected function errorResponse(string $message, Lead $lead, array $data = []): array
    {
        return [
            'message' => $message,
            'status' => 'error',
            'data' => $data ?: $lead->getId(),
        ];
    }

    protected function getEngagement(Lead $lead): ?Engagement
    {
        return EngagementRepository::findEngagementForLead(
            $lead,
            'credit-app',
            ActionStatusEnum::SUBMITTED->value
        );
    }

    protected function extractMessageData($message): ?array
    {
        return $message?->message['data']['form'] ?? null;
    }

    protected function processCreditScore(array $messageData, Lead $lead, Apps $app, array $params): array
    {
        $personal = $messageData['personal'];
        $housing = $messageData['housing'];

        $creditScoreService = new CreditScoreService($app);

        return $creditScoreService->getCreditScore(
            new CreditApplicant(
                "{$personal['first_name']} {$personal['last_name']}",
                $housing['address'],
                $housing['city'],
                $housing['state']['code'],
                $housing['zip_code'],
                $personal['ssn']
            ),
            $lead->user,
            $params['provider'] ?? 'TU'
        );
    }

    protected function createMessage(array $data, Lead $lead, Apps $app, $user, $company, ?int $parentId = null): object
    {
        $engagementMessage = new EngagementMessage(
            data: $data,
            text: ConfigurationEnum::ACTION_VERB->value,
            verb: ConfigurationEnum::ACTION_VERB->value,
            hashtagVisited: ConfigurationEnum::ACTION_VERB->value,
            actionLink: 'http://nolink.com',
            source: 'workflow',
            linkPreview: 'http://nolink.com',
            engagementStatus: $parentId ? ActionStatusEnum::SUBMITTED->value : ActionStatusEnum::SENT->value,
            visitorId: Str::uuid()->toString(),
            status: $parentId ? ActionStatusEnum::SUBMITTED->value : ActionStatusEnum::SENT->value,
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

        if ($parentId) {
            $messageInput['parent_id'] = $parentId;
        }

        $createMessage = new CreateMessageAction(
            MessageInput::fromArray(
                $messageInput,
                $user,
                MessageType::fromApp($app)->where('verb', ConfigurationEnum::ACTION_VERB->value)->firstOrFail(),
                $company,
                $app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $app),
            $lead->getId()
        );

        return $createMessage->execute();
    }

    protected function createParentMessage(array $data, Lead $lead, Apps $app, $message): object
    {
        return $this->createMessage($data, $lead, $app, $message->user, $message->company);
    }

    protected function createChildMessage(array $data, Lead $lead, Apps $app, $message, $parentMessage): object
    {
        $childMessage = $this->createMessage($data, $lead, $app, $message->user, $message->company, $parentMessage->getId());

        if (! empty($data['pdf']) && $data['pdf'] instanceof Filesystem) {
            $childMessage->addFile($data['pdf'], 'credit_score_report.pdf');
        }

        return $childMessage;
    }

    protected function distributeMessages(Lead $lead, Apps $app, $parentMessage, $childMessage): void
    {
        $leadChannel = Channel::query()
            ->whereIn('apps_id', [$app->getId(), AppEnums::LEGACY_APP_ID->getValue()])
            ->where('entity_id', $lead->getId())
            ->whereIn('entity_namespace', [Lead::class, SystemModules::getLegacyNamespace(Lead::class)])
            ->firstOrFail();

        DistributionMessageService::sentToChannelFeed($leadChannel, $parentMessage);
        DistributionMessageService::sentToChannelFeed($leadChannel, $childMessage);
    }

    protected function createEngagements(Lead $lead, Apps $app, $parentMessage, $childMessage, $message): void
    {
        $action = Action::where('slug', ConfigurationEnum::ACTION_VERB->value)->firstOrFail();
        $companyAction = CompanyAction::fromApp($app)
            ->fromCompany($message->company)
            ->where('actions_id', $action->getId())
            ->firstOrFail();

        $sentStage = $companyAction->pipeline->stages()
            ->where('slug', ActionStatusEnum::SENT->value)
            ->firstOrFail();
        $submittedStage = $companyAction->pipeline->stages()
            ->where('slug', ActionStatusEnum::SUBMITTED->value)
            ->firstOrFail();

        $this->createEngagement($parentMessage, $lead, $app, $message, $sentStage, $companyAction);
        $this->createEngagement($childMessage, $lead, $app, $message, $submittedStage, $companyAction);
    }

    protected function createEngagement($message, Lead $lead, Apps $app, $originalMessage, $stage, $companyAction): void
    {
        Engagement::firstOrCreate([
            'companies_id' => $message->company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $message->user->getId(),
            'message_id' => $message->getId(),
            'leads_id' => $lead->getId(),
            'slug' => ConfigurationEnum::ACTION_VERB->value,
            'people_id' => $lead->people->getId(),
            'pipelines_stages_id' => $stage->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'entity_uuid' => $lead->uuid,
        ]);
    }
}
