<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\RecordMailgunDeliveryEventAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Mailgun's delivery events for outbound email — the email side of what
 * ProcessTwilioMessageStatusWebhookJob does for SMS. Opens stay with EmailOpenedWebhookJob. The
 * communication webhook records the same events for a domain set up through the integration.
 */
#[WorkflowAction(
    name: 'Mailgun Email Delivery Status',
    description: 'Receiver for Mailgun delivery events on outbound email (delivered, failed, '
        . 'complained, unsubscribed): records whether an email was delivered or failed on the message, '
        . 'marks a hard-bounced address as bounced, and treats a spam complaint or an unsubscribe as a '
        . 'stop request. One per sending domain, registered with kanvas:mailgun-setup-delivery-webhooks. '
        . 'Opens are a separate '
        . 'receiver.',
    integration: IntegrationsEnum::MAILGUN,
)]
class EmailDeliveryWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new RecordMailgunDeliveryEventAction(
            $this->receiver->app,
            new MailgunEngagementPayloadService($this->webhookRequest->payload)
        )->execute();
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return VerifyMailgunWebhookSignatureAction::forRequest($request, $receiver)->execute();
    }
}
