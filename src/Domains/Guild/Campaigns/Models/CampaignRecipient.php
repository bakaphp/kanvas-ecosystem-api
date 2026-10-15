<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Override;

/**
 * One recipient's slot in a batch — its send result (sent / failed / skipped) and, on a miss, the
 * reason and destination used. The per-recipient audit trail behind a campaign's result view.
 *
 * `leads_id` is nullable: a recipient with no Lead (e.g. a bulk-imported People with no
 * opportunity behind it) is sent via `people()` instead — see `ProcessLeadCampaignJob`.
 *
 * @property int         $id
 * @property int         $apps_id
 * @property int         $companies_id
 * @property string      $uuid
 * @property int         $lead_campaigns_id
 * @property int|null    $leads_id
 * @property int|null    $peoples_id
 * @property string      $status
 * @property string|null $reason
 * @property string|null $destination
 * @property Carbon|null $sent_at
 */
class CampaignRecipient extends BaseModel
{
    use UuidTrait;

    protected $table = 'lead_campaign_recipients';
    protected $guarded = ['id'];

    #[Override]
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'lead_campaigns_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id');
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'peoples_id');
    }

    /**
     * A new, unsaved PENDING slot for a campaign — the field assignment every recipient-creation
     * site shares (CreateBatchCampaignAction, AddRecipientsToCampaignAction). Exactly one of
     * $leadId / $peopleId is expected to be non-null; the caller decides which.
     */
    public static function pending(Campaign $campaign, ?int $leadId, ?int $peopleId): self
    {
        $recipient = new self();
        $recipient->apps_id = $campaign->apps_id;
        $recipient->companies_id = $campaign->companies_id;
        $recipient->lead_campaigns_id = $campaign->getId();
        $recipient->leads_id = $leadId;
        $recipient->peoples_id = $peopleId;
        $recipient->status = CampaignRecipientStatusEnum::PENDING->value;

        return $recipient;
    }
}
