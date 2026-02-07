<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

class AddLeadCommentFromAgentMessageActivity extends BaseAddLeadCommentFromAgentMessageActivity
{
    #[Override]
    protected function getIntegration(): IntegrationsEnum
    {
        return IntegrationsEnum::VIN_SOLUTION;
    }

    #[Override]
    protected function validateCompanyIntegration(Message $message): ?array
    {
        if (! $message->company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        return null;
    }

    #[Override]
    protected function getManagerNotifiedAtKey(): ?string
    {
        return ConfigurationEnum::MANAGER_NOTIFIED_AT->value;
    }

    #[Override]
    protected function addNoteToExternalSystem(
        Lead $lead,
        string $note,
        Message $message,
        Apps $app
    ): mixed {
        if ($message->isLocked()) {
            return $this->failWorkflow([
                'error' => 'Message is locked, cannot add comment',
            ]);
        }

        return new PushNoteToLeadAction(
            lead: $lead,
            message: $message,
        )->execute($note);
    }
}
