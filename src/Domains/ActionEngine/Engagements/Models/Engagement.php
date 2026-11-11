<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Models;

use Baka\Contracts\EngageableInterface;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Observers\EngagementObserver;
use Kanvas\ActionEngine\Engagements\Relations\EngagementReferenceMorphTo;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStageMessage;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Nevadskiy\Tree\AsTree;

/**
 * Class Engagement.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_id
 * @property string|null $path
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $companies_actions_id
 * @property int $message_id
 * @property int|null $leads_id
 * @property int|null $people_id
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property int $pipelines_stages_id
 * @property string $entity_uuid
 * @property string $slug
 */
#[ObservedBy(EngagementObserver::class)]
class Engagement extends BaseModel
{
    use UuidTrait;
    use AsTree;
    use CanUseWorkflow;
    use SoftDeletesTrait;

    protected $table = 'engagements';
    protected $guarded = [];
    public const string DELETED_AT = 'is_deleted';

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

    /**
     * The quote, invoice, lead, ... this engagement shares. A legacy row with no entity_type
     * resolves to its lead.
     */
    public function reference(): EngagementReferenceMorphTo
    {
        [$type] = EngagementReferenceMorphTo::referenceOf($this);

        if ($type === null) {
            return new EngagementReferenceMorphTo(
                $this->newQuery()->setEagerLoads([]),
                $this,
                'entity_id',
                null,
                'entity_type',
                'reference'
            );
        }

        $class = EngagementReferenceMap::classFor($type);
        $related = new $class();

        return new EngagementReferenceMorphTo(
            $related->newQuery(),
            $this,
            $this->entity_type ? 'entity_id' : 'leads_id',
            $related->getKeyName(),
            'entity_type',
            'reference'
        );
    }

    public function referenceType(): ?string
    {
        return EngagementReferenceMorphTo::referenceOf($this)[0];
    }

    public function referencesLead(): bool
    {
        return $this->referenceType() === EngagementReferenceMap::LEAD;
    }

    /**
     * What lead-only callers check: a legacy row (no entity_type) is always treated as a lead
     * engagement, whatever its leads_id holds.
     */
    public function hasNonLeadReference(): bool
    {
        return ! empty($this->entity_type) && $this->entity_type !== EngagementReferenceMap::LEAD;
    }

    /**
     * Rows written before entity_type existed only match through leads_id, so a lead reference
     * matches both shapes.
     */
    public function scopeWhereReference(Builder $query, EngageableInterface $reference): Builder
    {
        $alias = EngagementReferenceMap::aliasFor($reference);

        return $query->where(function (Builder $query) use ($alias, $reference): void {
            $query->where('entity_type', $alias)->where('entity_id', $reference->getId());

            if ($alias === EngagementReferenceMap::LEAD) {
                $query->orWhere(
                    fn (Builder $legacy) => $legacy->whereNull('entity_type')->where('leads_id', $reference->getId())
                );
            }
        });
    }

    public function scopeSharing(Builder $query, EngageableInterface $reference, string $action): Builder
    {
        return $query->whereReference($reference)
            ->where('apps_id', $reference->getEngagementApp()->getId())
            ->where('companies_id', $reference->getEngagementCompany()->getId())
            ->where('slug', $action)
            ->where('is_deleted', 0);
    }

    public function scopeInThreadOf(Builder $query, Engagement $engagement): Builder
    {
        return $query->where('apps_id', $engagement->apps_id)
            ->where('companies_id', $engagement->companies_id)
            ->where('entity_uuid', $engagement->entity_uuid)
            ->where('is_deleted', 0);
    }

    public function scopeAtStage(Builder $query, ActionStatusEnum $status): Builder
    {
        return $query->whereHas('stage', fn (Builder $stage) => $stage->where('slug', $status->value));
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

    public function childEngagements(): HasMany
    {
        return $this->children()->with('stage');
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(self::class, 'entity_uuid', 'entity_uuid')
            ->where('apps_id', $this->apps_id)
            ->where('companies_id', $this->companies_id)
            ->where('leads_id', $this->leads_id)
            ->where('people_id', $this->people_id)
            ->with('stage')
            ->orderBy('created_at');
    }

    public function isParent(): bool
    {
        return $this->parent_id === null && $this->childEngagements()->exists();
    }

    /**
     * Reads the message payload, not `stage->slug` like isComplete() — the two are different sources
     * of truth and can disagree. getMessage() because some apps persist double-encoded JSON, which
     * the raw cast hands back as a string.
     */
    public function hasSubmittedMessage(): bool
    {
        return ($this->message?->getMessage()['status'] ?? null) === ActionStatusEnum::SUBMITTED->value;
    }

    public function isComplete(): bool
    {
        if (! $this->isParent()) {
            return false;
        }

        $children = $this->childEngagements()->get();

        if ($children->isEmpty()) {
            return false;
        }

        return $children->every(function ($child) {
            /** @var Engagement $child */
            return $child->stage?->slug === ActionStatusEnum::SUBMITTED->value;
        });
    }

    public function completionProgress(): array
    {
        $children = $this->childEngagements()->get();
        $total = $children->count();
        $completed = $children->filter(function ($child) {
            /** @var Engagement $child */
            return $child->stage?->slug === ActionStatusEnum::SUBMITTED->value;
        })->count();

        return [
            'completed' => $completed,
            'total' => $total,
        ];
    }
}
