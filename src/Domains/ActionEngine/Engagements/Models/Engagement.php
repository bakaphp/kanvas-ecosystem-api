<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Models;

use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Observers\EngagementObserver;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStageMessage;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * Class Engagement.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $companies_actions_id
 * @property int $message_id
 * @property int $leads_id
 * @property int $people_id
 * @property int $pipelines_stages_id
 * @property string $entity_uuid
 * @property string $slug
 */
#[ObservedBy(EngagementObserver::class)]
class Engagement extends BaseModel
{
    use UuidTrait;
    use CanUseWorkflow;
    use SoftDeletesTrait;

    protected $table = 'engagements';
    protected $guarded = [];
    public const DELETED_AT = 'is_deleted';

    public function companyAction(): BelongsTo
    {
        return $this->belongsTo(CompanyAction::class, 'companies_actions_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'leads_id', 'id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipelines_stages_id', 'id');
    }

    public function getShareDate(): string
    {
        $createdAt = $this->created_at instanceof Carbon
            ? $this->created_at
            : Carbon::parse($this->created_at);

        if ($createdAt->isToday()) {
            return 'today';
        } elseif ($createdAt->isYesterday()) {
            return 'yesterday';
        } elseif ($createdAt->greaterThan(now()->subDays(7))) {
            return $createdAt->format('l');
        } else {
            return 'on ' . $createdAt->format('M. d');
        }
    }

    public function stageMessage(): BelongsTo
    {
        return $this->belongsTo(PipelineStageMessage::class, 'pipelines_stages_id', 'pipelines_stages_id');
    }

    public static function getByMessageId(int|string $messageId): self
    {
        return self::query()
            ->where('message_id', $messageId)
            ->firstOrFail();
    }
}
