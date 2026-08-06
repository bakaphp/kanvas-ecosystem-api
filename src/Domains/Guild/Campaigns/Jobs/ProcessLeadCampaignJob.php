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
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
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
            ->with('lead')
            ->get();

        foreach ($recipients as $recipient) {
            /** @var CampaignRecipient $recipient */
            $lead = $recipient->lead;

            if ($lead === null || (bool) $lead->get('do_not_contact')) {
                $this->markRecipient($recipient, CampaignRecipientStatusEnum::SKIPPED, 'do_not_contact');
                $skipped++;

                continue;
            }

            $to = $resolver->deliverableContactValue($lead, $channel);
            if ($to === null) {
                $this->markRecipient($recipient, CampaignRecipientStatusEnum::SKIPPED, 'no_deliverable_contact');
                $skipped++;

                continue;
            }

            try {
                new SendMessageToLeadAction($lead)->execute(
                    channel: $channel,
                    message: $this->campaign->message,
                    title: $this->campaign->subject,
                    to: $to,
                );
                $recipient->destination = $to;
                $recipient->sent_at = Carbon::now();
                $this->markRecipient($recipient, CampaignRecipientStatusEnum::SENT);
                $sent++;

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
            } catch (Throwable $e) {
                report($e);
                $this->markRecipient(
                    $recipient,
                    CampaignRecipientStatusEnum::FAILED,
                    substr($e->getMessage(), 0, 64)
                );
                $failed++;
            }
        }

        $this->campaign->sent_count = $sent;
        $this->campaign->failed_count = $failed;
        $this->campaign->skipped_count = $skipped;
        $this->campaign->status = ($sent === 0 && $failed > 0)
            ? CampaignStatusEnum::FAILED->value
            : CampaignStatusEnum::SENT->value;
        $this->campaign->saveOrFail();
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
