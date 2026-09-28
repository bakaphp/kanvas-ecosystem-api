<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Connectors\Mailgun\Services\MailgunAttachmentService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class AttachEmailAttachmentsToLeadAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest,
        protected Lead $lead,
    ) {
    }

    /**
     * @return array<int, string> names of the files attached to the lead
     */
    public function execute(): array
    {
        return new MailgunAttachmentService($this->webhookRequest)->attachTo($this->lead);
    }
}
