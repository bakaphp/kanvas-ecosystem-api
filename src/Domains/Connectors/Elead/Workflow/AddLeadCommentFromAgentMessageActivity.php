<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Baka\Support\Str;
use Baka\Support\Url;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
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
        return IntegrationsEnum::ELEAD;
    }

    #[Override]
    protected function validateCompanyIntegration(Message $message): ?array
    {
        if (! $message->company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return null;
    }

    #[Override]
    protected function appendLinkBeforePrefix(): bool
    {
        return true;
    }

    #[Override]
    protected function appendAiChatLink(string $note, ?string $aiChatLink, Apps $app, bool $fromAgent): string
    {
        // Elead only appends link for agent messages
        if ($aiChatLink === null || ! $fromAgent) {
            return $note;
        }

        $shortUrl = Url::getShortUrl($aiChatLink, $app) . '?openInSa=true';

        return $note . " \n\n View Full Conversation here: {$shortUrl}";
    }

    #[Override]
    protected function addNoteToExternalSystem(
        Lead $lead,
        string $note,
        Message $message,
        Apps $app
    ): mixed {
        try {
            $eLeadOpportunity = EntitiesLead::getById($app, $lead->company, (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));
        } catch (ServerException $e) {
            return $this->failWorkflow([
                'error' => 'Elead Opportunity fetch error: ' . $e->getMessage(),
            ]);
        } catch (ClientException $e) {
            if (Str::contains($e->getMessage(), '404')) {
                $lead->close();
            }

            return $this->failWorkflow([
                'error' => 'Elead Opportunity fetch error: ' . $e->getMessage(),
            ]);
        }

        try {
            $eLeadOpportunity->addComment($note);

            return $note;
        } catch (ClientException $e) {
            if (Str::contains($e->getMessage(), 'not active')
                || Str::contains($e->getMessage(), 'InactiveOpportunity')) {
                $lead->close();
            }

            return $this->failWorkflow([
                'error' => 'Elead Opportunity add comment error: ' . $e->getMessage(),
            ]);
        }
    }
}
