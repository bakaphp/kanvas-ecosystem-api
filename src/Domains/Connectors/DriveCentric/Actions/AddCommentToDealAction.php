<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Support\Url;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Connectors\SalesAssist\Services\MessageNoteService;
use Kanvas\Connectors\SalesAssist\Services\MessageNotificationTextService;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class AddCommentToDealAction
{
    protected LeadService $leadService;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->leadService = new LeadService($this->lead->app, $this->lead->company);
    }

    public function execute(string|Message $comment): array
    {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            $pushLeadAction = new PushLeadAction($this->lead);
            $pushLeadAction->execute();
            $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        }

        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);

        if ($comment instanceof Message) {
            $comment = $this->getNote($comment);
        }

        $dealData['comments'] = $comment;

        return $this->leadService->upsertDeal($dealData);
    }

    protected function getNote(Message $message): ?string
    {
        /** @var string $linkPreview */
        $messageData = $message->getMessage();
        $linkPreview = '';

        try {
            $linkPreview = Url::getShortUrl($messageData['link'] ?? '', $this->lead->app);
        } catch (Throwable $e) {
            $linkPreview = $messageData['link'] ?? '';
        }

        $messageNote = new MessageNoteService($message);
        $newLink = $messageNote->generateFileLinks();
        if ($newLink !== null && $newLink !== '') {
            $linkPreview = $newLink;
        }

        /** @var string $linkPreview */

        // Get the message from the engagement service
        // This is the parent msg engagement
        try {
            $parentEngagement = $message->getEngagement();
            // Need to look for the current engagement via status
            $currentEngagement = EngagementRepository::findEngagementForLeadAndEntity(
                $this->lead,
                $messageData['verb'],
                $messageData['status'],
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
