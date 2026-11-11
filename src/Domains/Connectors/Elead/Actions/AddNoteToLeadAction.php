<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Support\Url;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\Connectors\Elead\Entities\Lead as ELeadEntity;
use Kanvas\Connectors\SalesAssist\Services\MessageNoteService;
use Kanvas\Connectors\SalesAssist\Services\MessageNotificationTextService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class AddNoteToLeadAction
{
    public function __construct(
        protected Lead $lead,
        protected Message $message,
    ) {
    }

    public function execute(ELeadEntity $eLeadOpportunity): string
    {
        $note = $this->getNote($this->message->getMessage());

        if ($note !== null && $note !== '') {
            $eLeadOpportunity->addComment($note);
        }

        return $note ?? '';
    }

    protected function getNote(array $message): ?string
    {
        $linkPreview = '';

        try {
            $linkPreview = Url::getShortUrl($message['link'] ?? '', $this->lead->app, company: $this->lead->company);
        } catch (Throwable $e) {
            $linkPreview = $message['link'] ?? '';
        }

        $messageNote = new MessageNoteService($this->message);
        $newLink = $messageNote->generateFileLinks();
        if ($newLink !== null && $newLink !== '') {
            $linkPreview = $newLink;
        }

        try {
            $parentEngagement = $this->message->getEngagement();
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
