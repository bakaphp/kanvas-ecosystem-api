<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\RecordMailgunOpenAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Domain-level Mailgun event webhook for `opened` — a different receiver shape from the inbound
 * mail routes (AgentInboxWebhookJob, AgentProcessEmailWebhookJob). Registered once per sending
 * domain via Client::createOrUpdateWebhook() and fired for every outbound message Mailgun delivers
 * on it, so it needs no receiver configuration: who to record against comes from the user-variables
 * the sender stamped on the message. The communication webhook records opens the same way for a
 * domain set up through the integration.
 */
#[WorkflowAction(
    name: 'Mailgun Email Opened',
    description: 'Domain-wide receiver for Mailgun\'s "opened" event webhook. Register the domain '
        . 'once with Client::createOrUpdateWebhook() pointed at this receiver\'s URL. Every email sent '
        . 'with open tracking on (SendAsAgentMailboxAction\'s trackingPerson/trackingLead) gets an '
        . 'activity note — on the Lead\'s own feed when the mail was about one, otherwise on the '
        . 'People\'s. A campaign email (v:campaign_recipient_id) also marks that campaign slot opened, '
        . 'and an email on record as a delivery (matched by Message-Id) is marked read. '
        . 'Needs no receiver configuration — read off the event\'s own user-variables.',
    integration: IntegrationsEnum::MAILGUN,
)]
class EmailOpenedWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new RecordMailgunOpenAction(
            $this->receiver->app,
            $this->receiver->company,
            new MailgunEngagementPayloadService($this->webhookRequest->payload)
        )->execute();
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return VerifyMailgunWebhookSignatureAction::forRequest($request, $receiver)->execute();
    }
}
