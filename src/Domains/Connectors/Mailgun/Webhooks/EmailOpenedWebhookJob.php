<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Models\People;
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
 * on it, so it needs no `agent_id`-style receiver configuration: the People to record against comes
 * from the `v:people_id` variable the sender stamped on the message (see
 * SendAsAgentMailboxAction::$trackingPerson), not from anything on the receiver row.
 */
#[WorkflowAction(
    name: 'Mailgun Email Opened',
    description: 'Domain-wide receiver for Mailgun\'s "opened" event webhook. Register the domain '
        . 'once with Client::createOrUpdateWebhook() pointed at this receiver\'s URL. Every email sent '
        . 'with open tracking on (SendAsAgentMailboxAction\'s trackingPerson) gets an activity note on '
        . 'that person when it is opened. Needs no receiver configuration — the person is read off the '
        . 'event\'s own user-variables, not the receiver.',
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

        $eventId = $payload->eventId();
        if ($eventId !== '' && ! Cache::add('mailgun:email-opened:' . $eventId, true, self::DEDUPE_TTL_SECONDS)) {
            return ['message' => 'Duplicate delivery'];
        }

        $peopleId = $payload->userVariable('people_id');
        if ($peopleId === null || $peopleId === '') {
            return ['message' => 'Event carries no people_id, nothing to record against'];
        }

        try {
            /** @var People $people */
            $people = People::getByIdFromCompanyApp((int) $peopleId, $this->receiver->company, $this->receiver->app);
        } catch (ModelNotFoundException) {
            return ['message' => 'people_id ' . $peopleId . ' no longer exists in this tenant'];
        }

        new RecordPeopleNoteAction($people)->execute(
            body: 'Opened email' . ($payload->recipient() !== '' ? ' (' . $payload->recipient() . ')' : ''),
            tag: 'email-opened',
            messageTypeVerb: 'email_opened',
        );

        return ['message' => 'Recorded', 'people_id' => $people->getId()];
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
