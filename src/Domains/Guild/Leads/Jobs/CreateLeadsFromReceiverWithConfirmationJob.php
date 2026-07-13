<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Kanvas\Guild\Leads\Actions\SendLeadConfirmationToContactAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;

/**
 * Lead receiver that also emails the submitter a confirmation carrying a short reference code
 * (the last 6 characters of the lead uuid). Gated by the webhook's
 * `configuration.send_confirmation` flag so it can be wired for every receiver yet only fire
 * where the tenant opted in.
 */
#[WorkflowAction]
class CreateLeadsFromReceiverWithConfirmationJob extends CreateLeadsFromReceiverJob
{
    #[Override]
    protected function afterLeadCreated(Lead $lead, LeadReceiver $leadReceiver, array $payload): void
    {
        if ((bool) ($this->receiver->configuration['send_confirmation'] ?? false) === false) {
            return;
        }

        new SendLeadConfirmationToContactAction(
            $lead,
            $this->resolveConfirmationChannels(),
        )->execute();
    }

    /**
     * Which channels to send the confirmation on (e.g. ["mail"], ["sms"], or both),
     * read from the receiver config. Falls back to email when nothing is set.
     */
    private function resolveConfirmationChannels(): array
    {
        $channels = $this->receiver->configuration['confirmation_channels'] ?? ['mail'];

        if (! is_array($channels) || $channels === []) {
            return ['mail'];
        }

        return $channels;
    }
}
