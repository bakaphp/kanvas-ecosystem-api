<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Actions\SendEmailToPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Users\Models\Users;
use Throwable;

class ProcessLeadCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public readonly Apps $app,
        public readonly Campaign $campaign,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $resolver = new BatchRecipientResolverService();
        $channel = $this->campaign->channel;
        $manager = Users::getById($this->campaign->users_id, $this->app);

        $this->campaign->status = CampaignStatusEnum::SENDING->value;
        $this->campaign->saveOrFail();

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $recipients = $this->campaign->recipients()
            ->where('status', CampaignRecipientStatusEnum::PENDING->value)
            ->where('is_deleted', 0)
            ->with(['lead', 'people'])
            ->get();

        foreach ($recipients as $recipient) {
            /** @var CampaignRecipient $recipient */
            $result = $recipient->leads_id !== null
                ? $this->sendToLead($recipient, $resolver, $channel, $manager)
                : $this->sendToPeople($recipient, $resolver, $channel, $manager);

            match ($result) {
                'sent' => $sent++,
                'failed' => $failed++,
                'skipped' => $skipped++,
            };
        }

        $this->campaign->sent_count = $sent;
        $this->campaign->failed_count = $failed;
        $this->campaign->skipped_count = $skipped;
        $this->campaign->status = ($sent === 0 && $failed > 0)
            ? CampaignStatusEnum::FAILED->value
            : CampaignStatusEnum::SENT->value;
        $this->campaign->saveOrFail();
    }

    private function sendToLead(
        CampaignRecipient $recipient,
        BatchRecipientResolverService $resolver,
        string $channel,
        Users $manager,
    ): string {
        $lead = $recipient->lead;

        if ($lead === null || (bool) $lead->get('do_not_contact')) {
            $this->markRecipient($recipient, CampaignRecipientStatusEnum::SKIPPED, 'do_not_contact');

            return 'skipped';
        }

        $to = $resolver->deliverableContactValue($lead, $channel);
        if ($to === null) {
            $this->markRecipient($recipient, CampaignRecipientStatusEnum::SKIPPED, 'no_deliverable_contact');

            return 'skipped';
        }

        try {
            $sent = new SendMessageToLeadAction($lead)->execute(
                channel: $channel,
                message: $this->campaign->message,
                title: $this->campaign->subject,
                to: $to,
            );
            $recipient->destination = $to;
            $recipient->sent_at = Carbon::now();
            $this->markRecipient($recipient, CampaignRecipientStatusEnum::SENT);
            $this->recordOutboundMessage(
                $lead,
                $channel,
                $to,
                $manager,
                $sent
            );

            // Per-customer audit note in the lead's own timeline, attributed to the manager
            // who ran the batch (not the AI). Self-guards; a note miss never fails the send.
            new RecordLeadNoteAction($lead)->execute(
                $this->campaign->subject !== null
                    ? $this->campaign->subject . "\n\n" . $this->campaign->message
                    : $this->campaign->message,
                'campaign',
                $manager,
                false,
            );

            return 'sent';
        } catch (Throwable $e) {
            report($e);
            $this->markRecipient(
                $recipient,
                CampaignRecipientStatusEnum::FAILED,
                substr($e->getMessage(), 0, 64)
            );

            return 'failed';
        }
    }

    /**
     * The people-only counterpart of sendToLead() — a recipient with no Lead behind it (a
     * bulk-imported campaign recipient). Email only: resolvePeople()/deliverableContactValueForPeople()
     * is the only People-shaped vetting the resolver has in v1.
     */
    private function sendToPeople(
        CampaignRecipient $recipient,
        BatchRecipientResolverService $resolver,
        string $channel,
        Users $manager,
    ): string {
        $people = $recipient->people;

        if ($people === null || (bool) $people->get('do_not_contact')) {
            $this->markRecipient($recipient, CampaignRecipientStatusEnum::SKIPPED, 'do_not_contact');

            return 'skipped';
        }

        $to = $resolver->deliverableContactValueForPeople($people, $channel);
        if ($to === null) {
            $this->markRecipient($recipient, CampaignRecipientStatusEnum::SKIPPED, 'no_deliverable_contact');

            return 'skipped';
        }

        try {
            $sent = new SendEmailToPeopleAction($people)->execute(
                to: $to,
                message: $this->campaign->message,
                subject: $this->campaign->subject,
            );
            $recipient->destination = $to;
            $recipient->sent_at = Carbon::now();
            $this->markRecipient($recipient, CampaignRecipientStatusEnum::SENT);
            $this->recordOutboundMessage(
                $people,
                $channel,
                $to,
                $manager,
                $sent
            );

            new RecordPeopleNoteAction($people)->execute(
                $this->campaign->subject !== null
                    ? $this->campaign->subject . "\n\n" . $this->campaign->message
                    : $this->campaign->message,
                'campaign',
                $manager,
                false,
            );

            return 'sent';
        } catch (Throwable $e) {
            report($e);
            $this->markRecipient(
                $recipient,
                CampaignRecipientStatusEnum::FAILED,
                substr($e->getMessage(), 0, 64)
            );

            return 'failed';
        }
    }

    /**
     * Already delivered, so a failure here is reported and swallowed: it must never mark a sent
     * recipient failed, which would invite a resend.
     *
     * @param array<string, mixed> $providerResponse
     */
    private function recordOutboundMessage(
        Lead|People $entity,
        string $channel,
        string $to,
        Users $manager,
        array $providerResponse
    ): void {
        try {
            new PersistOutboundMessageAction(
                entity: $entity,
                user: $manager,
                channelType: $channel,
                recipient: $to,
                content: $this->campaign->message,
                subject: $this->campaign->subject,
                fromAi: false,
                tag: 'campaign',
            )->execute($providerResponse);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function markRecipient(
        CampaignRecipient $recipient,
        CampaignRecipientStatusEnum $status,
        ?string $reason = null
    ): void {
        $recipient->status = $status->value;
        if ($reason !== null) {
            $recipient->reason = $reason;
        }
        $recipient->saveOrFail();
    }
}
