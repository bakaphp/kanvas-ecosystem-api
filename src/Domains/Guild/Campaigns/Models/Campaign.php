<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Models\BaseModel;
use Override;

/**
 * A batch outreach send initiated by a manager (via Polly): the message, the channel, a snapshot of
 * the trait criteria that produced the audience, and the running result counts. Append-only history —
 * one row per batch — read back by the get_batch_history tool.
 *
 * @property int         $id
 * @property int         $apps_id
 * @property int         $companies_id
 * @property string      $uuid
 * @property int         $users_id
 * @property string      $channel
 * @property string      $message
 * @property array|null  $criteria
 * @property string      $status
 * @property Carbon|null $scheduled_at
 * @property int         $total_recipients
 * @property int         $sent_count
 * @property int         $failed_count
 * @property int         $skipped_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Campaign extends BaseModel
{
    use UuidTrait;

    protected $table = 'lead_campaigns';
    protected $guarded = ['id'];

    #[Override]
    protected function casts(): array
    {
        return [
            'criteria' => Json::class,
            'scheduled_at' => 'datetime',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'lead_campaigns_id');
    }
}
