<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'Reynolds Add Lead Comment From Agent Message',
    description: 'Copies an agent\'s message into the Reynolds lead as a comment, so the CRM shows what the '
        . 'agent said. Writes to Reynolds only; it sends nothing to the customer.',
    integration: IntegrationsEnum::REYNOLDS,
)]
class AddLeadCommentFromAgentMessageActivity extends BaseAddLeadCommentFromAgentMessageActivity
{
    #[Override]
    protected function getIntegration(): IntegrationsEnum
    {
        return IntegrationsEnum::REYNOLDS;
    }

    #[Override]
    protected function validateCompanyIntegration(Message $message): ?array
    {
        $company = $message->company;

        if (empty($company->get(ConfigurationEnum::REYNOLDS_ENDPOINT->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_USERNAME->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_PASSWORD->value))
        ) {
            return ['error' => 'Reynolds credentials are not configured for this company'];
        }

        if (empty($company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value))
        ) {
            return ['error' => 'Reynolds dealer/store/area not configured for this company'];
        }

        return null;
    }

    #[Override]
    protected function addNoteToExternalSystem(
        Lead $lead,
        string $note,
        Message $message,
        Apps $app
    ): mixed {
        try {
            return new AddNoteToLeadAction($lead, $note)->execute();
        } catch (Throwable $e) {
            return $this->failWorkflow([
                'error' => 'Reynolds USL Note failed: ' . $e->getMessage(),
            ]);
        }
    }
}
