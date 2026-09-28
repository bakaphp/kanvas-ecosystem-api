<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Override;

/**
 * One lead's slot in a batch — its send result (sent / failed / skipped) and, on a miss, the reason
 * and destination used. The per-recipient audit trail behind a campaign's result view.
 *
 * @property int         $id
 * @property int         $apps_id
 * @property int         $companies_id
 * @property string      $uuid
 * @property int         $lead_campaigns_id
 * @property int         $leads_id
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
}
