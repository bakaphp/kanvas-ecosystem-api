<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Activities;

use Baka\Support\Url;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

class AddLeadCommentFromAgentMessageActivity extends BaseAddLeadCommentFromAgentMessageActivity
{
    #[Override]
    protected function getIntegration(): IntegrationsEnum
    {
        return IntegrationsEnum::DEALERSOCKET;
    }

    #[Override]
    protected function validateCompanyIntegration(Message $message): ?array
    {
        // DealerSocket doesn't have a specific company validation
        return null;
    }

    #[Override]
    protected function appendAiChatLink(string $note, ?string $aiChatLink, Apps $app, bool $fromAgent): string
    {
        if ($aiChatLink === null) {
            return $note;
        }

        $shortUrl = '<a href=' . Url::getShortUrl($aiChatLink, $app) . '?openInSa=true" target="_blank">AI Chat Conversation</a>';
        $linkText = "<br />View Full Conversation here: {$shortUrl}";

        if (strlen($note) + strlen($linkText) > 200) {
            return substr($note, 0, 200 - strlen($linkText) - 5) . '...' . $linkText;
        }

        return $note . $linkText;
    }

    #[Override]
    protected function addNoteToExternalSystem(Lead $lead, string $note, Message $message, Apps $app): mixed
    {
        $leadNote = new DealerSocketWorkNoteService(
            $lead->app,
            $lead->company
        );

        return $leadNote->addHtmlNote($lead, $note);
    }
}
