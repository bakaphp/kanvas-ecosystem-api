<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Jobs\ProcessLeadCampaignJob;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Users\Models\Users;

/**
 * Persists a batch campaign and its (already-vetted) recipients, then dispatches the fan-out job —
 * immediately, or delayed when scheduled. The recipient set must already be the resolver's eligible
 * output; this action does not re-filter (the job re-checks deliverability at send time).
 */
class CreateBatchCampaignAction
{
    /**
     * @param  array<int, array<string, mixed>>  $eligibleRecipients  Resolver eligible rows (lead_id, people_id) —
     *     exactly one of the two is non-null per row (resolve()'s rows always carry a lead_id,
     *     resolvePeople()'s always carry a people_id and a null lead_id).
     * @param  array<string, mixed>  $criteria
     * @param  list<string>  $attachmentUrls  Shared by every recipient in this campaign — the same
     *     file(s) go to everyone, there's no per-recipient attachment.
     * @param  ?string  $templateName  A `templates` row name (see TemplatesRepository::getByName()) —
     *     null keeps the sender's own default rather than forcing one here.
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $channel,
        private readonly string $message,
        private readonly ?string $subject,
        private readonly array $eligibleRecipients,
        private readonly array $criteria = [],
        private readonly array $attachmentUrls = [],
        private readonly ?string $templateName = null,
        private readonly ?Carbon $scheduledAt = null,
    ) {
    }

    public function execute(): Campaign
    {
        $campaign = DB::connection('crm')->transaction(function (): Campaign {
            $campaign = new Campaign();
            $campaign->apps_id = $this->app->getId();
            $campaign->companies_id = $this->company->getId();
            $campaign->users_id = $this->user->getId();
            $campaign->channel = $this->channel;
            $campaign->subject = $this->subject;
            $campaign->message = $this->message;
            $campaign->criteria = $this->criteria;
            $campaign->attachment_urls = $this->attachmentUrls !== [] ? $this->attachmentUrls : null;
            $campaign->template_name = $this->templateName;
            $campaign->scheduled_at = $this->scheduledAt;
            $campaign->status = $this->scheduledAt !== null
                ? CampaignStatusEnum::SCHEDULED->value
                : CampaignStatusEnum::SENDING->value;
            $campaign->total_recipients = count($this->eligibleRecipients);
            $campaign->saveOrFail();

            foreach ($this->eligibleRecipients as $row) {
                CampaignRecipient::pending(
                    $campaign,
                    isset($row['lead_id']) ? (int) $row['lead_id'] : null,
                    ((int) ($row['people_id'] ?? 0)) ?: null,
                )->saveOrFail();
            }

            return $campaign;
        });

        // Dispatch after commit so the worker never races an uncommitted campaign.
        $job = ProcessLeadCampaignJob::dispatch($this->app, $campaign);
        if ($this->scheduledAt !== null) {
            $job->delay($this->scheduledAt);
        }

        return $campaign;
    }
}
