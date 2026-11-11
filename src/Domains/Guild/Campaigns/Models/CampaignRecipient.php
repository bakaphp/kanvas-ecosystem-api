<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Models;

use Baka\Casts\Json;
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
 * @property list<string>|null $attachment_urls  This recipient's own files, sent on top of the campaign's shared ones.
 * @property array<string, mixed>|null $variables  Free-form template context for this recipient, laid over `people`.
 * @property Carbon|null $sent_at
 * @property Carbon|null $opened_at
 * @property int         $open_count
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
            'opened_at' => 'datetime',
            'open_count' => 'integer',
            'attachment_urls' => Json::class,
            'variables' => Json::class,
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
     * The campaign's shared files first, then this recipient's own, each URL once.
     *
     * @param list<string> $sharedUrls
     * @return list<string>
     */
    public static function mergeAttachmentUrls(array $sharedUrls, array $ownUrls): array
    {
        return array_values(array_unique([...$sharedUrls, ...$ownUrls]));
    }

    /**
     * A new, unsaved PENDING slot for a campaign — the field assignment every recipient-creation
     * site shares (CreateBatchCampaignAction, AddRecipientsToCampaignAction). Exactly one of
     * $leadId / $peopleId is expected to be non-null; the caller decides which.
     *
     * @param list<string> $attachmentUrls
     * @param array<string, mixed> $variables
     */
    public static function pending(
        Campaign $campaign,
        ?int $leadId,
        ?int $peopleId,
        array $attachmentUrls = [],
        array $variables = [],
    ): self {
        $recipient = new self();
        $recipient->apps_id = $campaign->apps_id;
        $recipient->companies_id = $campaign->companies_id;
        $recipient->lead_campaigns_id = $campaign->getId();
        $recipient->leads_id = $leadId;
        $recipient->peoples_id = $peopleId;
        $recipient->status = CampaignRecipientStatusEnum::PENDING->value;
        $recipient->attachment_urls = $attachmentUrls !== [] ? $attachmentUrls : null;
        $recipient->variables = $variables !== [] ? $variables : null;

        return $recipient;
    }
}
