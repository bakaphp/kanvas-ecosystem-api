<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Connectors\Mailgun\Actions\AttachEmailAttachmentsToLeadAction;
use Kanvas\Connectors\Mailgun\Actions\CreateMessageFromEmailAction;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

use function Sentry\captureMessage;

#[WorkflowAction]
class AgentProcessEmailWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $from = $this->webhookRequest->payload['sender'] ?? null;
        $lead = null;

        $people = PeoplesRepository::getByEmail(
            $from,
            $this->webhookRequest->receiverWebhook->company,
            $this->webhookRequest->receiverWebhook->app,
        );

        if ($people !== null) {
            $lead = LeadsRepository::getPeopleActiveLead($people);

            if ($lead === null && (bool) $this->webhookRequest->receiverWebhook->company->get('use_last_lead_on_responder')) {
                $lead = LeadsRepository::getPeopleLastLead($people);
            }
        }

        $message = new CreateMessageFromEmailAction($this->webhookRequest, $lead)
            ->execute();

        if ($lead !== null) {
            new AttachEmailAttachmentsToLeadAction($this->webhookRequest, $lead)->execute();
        }

        $this->assertAttachmentsCaptured();

        return $message->toArray();
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return new VerifyMailgunWebhookSignatureAction(
            $receiver->app,
            $receiver->company,
            (string) $request->input('token', ''),
            (string) $request->input('timestamp', ''),
            (string) $request->input('signature', ''),
        )->execute();
    }

    private function assertAttachmentsCaptured(): void
    {
        if (! (bool) ($this->receiver->configuration['capture_files'] ?? false)) {
            return;
        }

        $payload = $this->webhookRequest->payload;

        if (! is_array($payload)) {
            return;
        }

        $expected = (int) ($payload['attachment-count'] ?? 0);

        if ($expected === 0) {
            return;
        }

        $uploaded = $payload['uploaded_files'] ?? null;
        $captured = is_array($uploaded) ? count($uploaded) : 0;

        if ($captured < $expected) {
            captureMessage(sprintf(
                'Mailgun webhook call %d: %d attachment(s) reported but %d captured — files dropped before reaching the app.',
                $this->webhookRequest->getId(),
                $expected,
                $captured,
            ));
        }
    }
}
