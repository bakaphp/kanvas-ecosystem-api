<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\MailgunEngagementPayloadService;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Campaigns\Actions\RecordCampaignRecipientOpenAction;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * A Mailgun `opened` event, shared by the legacy opened receiver and the communication webhook: the
 * email's `read` on its delivery, the campaign slot opened, and a note on the lead or person the
 * sender stamped on the message (`v:lead_id` / `v:people_id`).
 *
 * The tenant is the matched delivery's, not the receiver's: a domain's opens all reach one URL, so
 * one company's receiver hears every company on that domain. The receiver's tenant is only the
 * fallback for mail sent before deliveries were filed.
 */
final class RecordMailgunOpenAction
{
    private const int DEDUPE_TTL_SECONDS = 86400;

    private ?Delivery $delivery = null;

    public function __construct(
        private AppInterface $app,
        private Companies $company,
        private readonly MailgunEngagementPayloadService $payload
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if ($this->payload->event() !== 'opened') {
            return ['message' => 'Not an opened event, ignored'];
        }

        // Mail Privacy Protection and scanners open a mail seconds after delivery whether or not
        // anyone read it.
        if ($this->payload->bot() !== '') {
            return ['message' => 'Automated open (' . $this->payload->bot() . '), ignored'];
        }

        $eventId = $this->payload->eventId();
        if ($eventId !== '' && ! Cache::add('mailgun:email-opened:' . $eventId, true, self::DEDUPE_TTL_SECONDS)) {
            return ['message' => 'Duplicate delivery'];
        }

        $this->delivery = RecordEmailEventOnDeliveryAction::findDelivery($this->app, $this->payload);

        if ($this->delivery !== null) {
            $this->app = $this->delivery->app;
            $this->company = $this->delivery->company;
        }

        $trackingResult = [...$this->recordOnDelivery(), ...$this->recordOnCampaignRecipient()];

        $leadId = $this->payload->userVariable('lead_id');
        if ($leadId !== null && $leadId !== '') {
            return [...$this->recordOnLead((int) $leadId), ...$trackingResult];
        }

        $peopleId = $this->payload->userVariable('people_id');
        if ($peopleId === null || $peopleId === '') {
            return ['message' => 'Event carries no people_id or lead_id, nothing to record against', ...$trackingResult];
        }

        return [...$this->recordOnPeople((int) $peopleId), ...$trackingResult];
    }

    /**
     * The open is the email's `read`, the same as WhatsApp's: matched by Message-Id like a delivery
     * event, so it needs none of the user-variables the note does.
     *
     * @return array<string, mixed>
     */
    private function recordOnDelivery(): array
    {
        if ($this->delivery === null) {
            return [];
        }

        new RecordEmailEventOnDeliveryAction($this->delivery, DeliveryStatusEnum::OPENED, $this->payload)->execute();

        return ['delivery_id' => $this->delivery->getId(), 'delivery_status' => $this->delivery->status->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordOnCampaignRecipient(): array
    {
        $recipientId = $this->payload->userVariable('campaign_recipient_id');
        if ($recipientId === null || $recipientId === '') {
            return [];
        }

        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::query()
            ->whereKey((int) $recipientId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
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

    private function recordOnLead(int $leadId): array
    {
        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($leadId, $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return ['message' => 'lead_id ' . $leadId . ' no longer exists in this tenant'];
        }

        new RecordLeadNoteAction($lead)->execute(
            body: $this->noteBody(),
            tag: 'email-opened',
            messageTypeVerb: 'email_opened',
        );

        return ['message' => 'Recorded', 'lead_id' => $lead->getId()];
    }

    private function recordOnPeople(int $peopleId): array
    {
        try {
            /** @var People $people */
            $people = People::getByIdFromCompanyApp($peopleId, $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return ['message' => 'people_id ' . $peopleId . ' no longer exists in this tenant'];
        }

        new RecordPeopleNoteAction($people)->execute(
            body: $this->noteBody(),
            tag: 'email-opened',
            messageTypeVerb: 'email_opened',
        );

        return ['message' => 'Recorded', 'people_id' => $people->getId()];
    }

    private function noteBody(): string
    {
        return 'Opened email' . ($this->payload->recipient() !== '' ? ' (' . $this->payload->recipient() . ')' : '');
    }
}
