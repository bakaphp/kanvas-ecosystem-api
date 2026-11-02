<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Campaigns\Actions\RecordCampaignRecipientOpenAction;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Domain-level Mailgun event webhook for `opened` — a different receiver shape from the rest of this
 * connector's webhooks (AgentInboxWebhookJob, AgentProcessEmailWebhookJob), which all handle an
 * inbound mail *route* (one matching recipient's reply). This one is registered once per sending
 * domain via Client::createOrUpdateWebhook() and fires for every outbound message Mailgun delivers
 * on it, so it needs no `agent_id`-style receiver configuration: who to record against comes from the
 * `v:people_id` / `v:lead_id` variables the sender stamped on the message (see
 * SendAsAgentMailboxAction::$trackingPerson/$trackingLead), not from anything on the receiver row.
 */
#[WorkflowAction(
    name: 'Mailgun Email Opened',
    description: 'Domain-wide receiver for Mailgun\'s "opened" event webhook. Register the domain '
        . 'once with Client::createOrUpdateWebhook() pointed at this receiver\'s URL. Every email sent '
        . 'with open tracking on (SendAsAgentMailboxAction\'s trackingPerson/trackingLead) gets an '
        . 'activity note — on the Lead\'s own feed when the mail was about one, otherwise on the '
        . 'People\'s. A campaign email (v:campaign_recipient_id) also marks that campaign slot opened. '
        . 'Needs no receiver configuration — read off the event\'s own user-variables.',
    integration: IntegrationsEnum::MAILGUN,
)]
class EmailOpenedWebhookJob extends ProcessWebhookJob
{
    private const int DEDUPE_TTL_SECONDS = 86400;

    #[Override]
    public function execute(): array
    {
        $payload = new MailgunEngagementPayloadService($this->webhookRequest->payload);

        if ($payload->event() !== 'opened') {
            return ['message' => 'Not an opened event, ignored'];
        }

        if ($payload->bot() !== '') {
            return ['message' => 'Automated open (' . $payload->bot() . '), ignored'];
        }

        $eventId = $payload->eventId();
        if ($eventId !== '' && ! Cache::add('mailgun:email-opened:' . $eventId, true, self::DEDUPE_TTL_SECONDS)) {
            return ['message' => 'Duplicate delivery'];
        }

        $campaignResult = $this->recordOnCampaignRecipient($payload);

        $leadId = $payload->userVariable('lead_id');
        if ($leadId !== null && $leadId !== '') {
            return [...$this->recordOnLead((int) $leadId, $payload), ...$campaignResult];
        }

        $peopleId = $payload->userVariable('people_id');
        if ($peopleId === null || $peopleId === '') {
            return ['message' => 'Event carries no people_id or lead_id, nothing to record against', ...$campaignResult];
        }

        return [...$this->recordOnPeople((int) $peopleId, $payload), ...$campaignResult];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordOnCampaignRecipient(MailgunEngagementPayloadService $payload): array
    {
        $recipientId = $payload->userVariable('campaign_recipient_id');
        if ($recipientId === null || $recipientId === '') {
            return [];
        }

        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::query()
            ->whereKey((int) $recipientId)
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->notDeleted()
            ->first();

        if ($recipient === null) {
            return ['campaign_recipient' => 'campaign_recipient_id ' . $recipientId . ' not found in this tenant'];
        }

        return [
            'campaign_recipient_id' => $recipient->getId(),
            'campaign_first_open' => new RecordCampaignRecipientOpenAction($recipient)->execute(),
        ];
    }

    private function recordOnLead(int $leadId, MailgunEngagementPayloadService $payload): array
    {
        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $this->receiver->company, $this->receiver->app);
        } catch (ModelNotFoundException) {
            return ['message' => 'lead_id ' . $leadId . ' no longer exists in this tenant'];
        }

        new RecordLeadNoteAction($lead)->execute(
            body: $this->noteBody($payload),
            tag: 'email-opened',
            messageTypeVerb: 'email_opened',
        );

        return ['message' => 'Recorded', 'lead_id' => $lead->getId()];
    }

    private function recordOnPeople(int $peopleId, MailgunEngagementPayloadService $payload): array
    {
        try {
            /** @var People $people */
            $people = People::getByIdFromCompanyApp($peopleId, $this->receiver->company, $this->receiver->app);
        } catch (ModelNotFoundException) {
            return ['message' => 'people_id ' . $peopleId . ' no longer exists in this tenant'];
        }

        new RecordPeopleNoteAction($people)->execute(
            body: $this->noteBody($payload),
            tag: 'email-opened',
            messageTypeVerb: 'email_opened',
        );

        return ['message' => 'Recorded', 'people_id' => $people->getId()];
    }

    private function noteBody(MailgunEngagementPayloadService $payload): string
    {
        return 'Opened email' . ($payload->recipient() !== '' ? ' (' . $payload->recipient() . ')' : '');
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return new VerifyMailgunWebhookSignatureAction(
            $receiver->app,
            $receiver->company,
            (string) $request->input('signature.token', ''),
            (string) $request->input('signature.timestamp', ''),
            (string) $request->input('signature.signature', ''),
        )->execute();
    }
}
