<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\DataTransferObject;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Spatie\LaravelData\Data;

class Engagement extends Data
{
    public readonly string $slug;

    public function __construct(
        public readonly CompanyAction $companyAction,
        public readonly Message $message,
        public readonly Lead $lead,
        public readonly PipelineStage $pipelineStage,
        public readonly string $entityUuid,
        ?string $slug = null,
    ) {
        $this->slug = $slug ?? $this->companyAction->slug;
    }

    public static function fromMultiple(
        CompanyAction $companyAction,
        Message $message,
        Lead $lead,
        PipelineStage $pipelineStage,
        string $entityUuid,
        ?string $slug = null,
    ): self {
        return new self(
            companyAction: $companyAction,
            message: $message,
            lead: $lead,
            pipelineStage: $pipelineStage,
            entityUuid: $entityUuid,
            slug: $slug,
        );
    }

    public static function withoutMessage(
        Lead $lead,
        string $actionSlug,
        ActionStatusEnum $stage,
        array $data,
        string $visitorUuid,
        array $options = []
    ) {
        $engagementMessage = new EngagementMessage(
            data: [
                'form' => $data,
            ],
            text: $actionSlug,
            verb: $actionSlug,
            hashtagVisited: $visitorUuid,
            actionLink: 'http://nolink.com',
            source: 'workflow',
            linkPreview: 'http://nolink.com',
            engagementStatus: $stage->value,
            visitorId: $visitorUuid,
            status: $stage->value,
            checklistId: $options['checklistId'] ?? null,
        );

        $createMessage = new CreateMessageAction(
            MessageInput::fromArray(
                [
                    'message' => $engagementMessage->toArray(),
                    'reactions_count' => 0,
                    'comments_count' => 0,
                    'total_liked' => 0,
                    'total_disliked' => 0,
                    'total_saved' => 0,
                    'total_shared' => 0,
                    'ip_address' => '127.0.0.1',
                ],
                $lead->user,
                MessageType::fromApp($lead->app)->where('verb', $actionSlug)->firstOrFail(),
                $lead->company,
                $lead->app
            ),
            SystemModulesRepository::getByModelName(Lead::class, $lead->app),
            $lead->getId()
        );

        $message = $createMessage->execute();

        $action = Action::getBySlug($actionSlug, $lead->app);
        $companyAction = CompanyAction::getByAction($action, $lead->company, $lead->app);

        return new self(
            companyAction: $companyAction,
            message: $message,
            lead: $lead,
            pipelineStage: $companyAction->pipeline->getStageBySlug($stage->value),
            entityUuid: $visitorUuid,
            slug: $actionSlug,
        );
    }
}
