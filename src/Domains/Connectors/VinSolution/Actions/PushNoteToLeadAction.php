<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Support\Url;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\Connectors\SalesAssist\Services\MessageNoteService;
use Kanvas\Connectors\SalesAssist\Services\MessageNotificationTextService;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Connectors\VinSolution\Services\LeadUserService;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class PushNoteToLeadAction
{
    public function __construct(
        protected ModelsLead $lead,
        protected Message $message,
    ) {
    }

    public function execute(?string $note = null): array
    {
        $vinCompany = Dealer::getById($this->lead->company->get(ConfigurationEnum::COMPANY->value), $this->lead->app);

        $vinUser = LeadUserService::resolve($this->lead);
        $vinUserId = $vinUser?->get(ConfigurationEnum::getUserKey($this->lead->company, $vinUser));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->lead->app,
        );

        $vinLeadId = $this->lead->get(CustomFieldEnum::LEADS->value);

        if (! $vinLeadId) {
            throw new VinSolutionException(
                'Lead not found in VinSolution',
            );
        }

        $vinLead = Lead::getById(
            $vinCompany,
            $user,
            $vinLeadId
        );

        $note = $note === null ? $this->getNote($this->message->getMessage()) : $note;

        $vinLead->addNotes(
            $vinCompany,
            $user,
            $note
        );

        return [
            $note,
        ];
    }

    protected function getNote(array $message): ?string
    {
        //$note = null;

        try {
            $linkPreview = Url::getShortUrl($message['link'] ?? '', $this->lead->app);
        } catch (Throwable $e) {
            $linkPreview = $message['link'] ?? null;
        }

        $messageNote = new MessageNoteService($this->message);
        if ($newLink = $messageNote->generateFileLinks()) {
            $linkPreview = $newLink;
        }

        //$messageService = new MessagesServices($this->entity);
        //$note = $messageService->getDisplayCardMessage() . ' ' . $linkPreview;

        //improvement to get the message from the message service
        //this is the parent msg engagement

        try {
            $parentEngagement = $this->message->getEngagement();
            //need to look for the current engagement via status
            $currentEngagement = EngagementRepository::findEngagementForLeadAndEntity(
                $this->lead,
                $message['verb'],
                $message['status'],
                $parentEngagement->entity_uuid
            );
        } catch (Throwable $e) {
            return null;
        }

        $engagementMessage = new MessageNotificationTextService($currentEngagement ?: $parentEngagement);
        $note = $engagementMessage->cardText() . ' ' . $linkPreview;

        return $note;
    }
}
