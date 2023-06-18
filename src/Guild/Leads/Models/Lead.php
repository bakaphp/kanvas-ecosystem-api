<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Support\Str;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Users\Models\Users;
use Laravel\Scout\Searchable;

/**
 * Class Leads.
 *
 * @property int $id
 * @property string $uuid
 * @property int $users_id
 * @property int $companies_id
 * @property int $companies_branches_id
 * @property int $leads_receivers_id
 * @property int $leads_owner_id
 * @property int $leads_status_id
 * @property int $leads_sources_id
 * @property int|null $pipeline_id
 * @property int|null $pipeline_stage_id
 * @property int $people_id
 * @property int $organization_id
 * @property int $leads_types_id
 * @property int $status
 * @property string $reason_lost
 * @property string $title
 * @property string $firstname
 * @property string $lastname
 * @property string $email
 * @property string $phone
 * @property string $description
 * @property string $is_duplicate
 * @property string $third_party_sync_status @deprecated version 0.3
 */
class Lead extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use NoAppRelationshipTrait;

    protected $table = 'leads';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function participants(): HasManyThrough
    {
        return $this->hasManyThrough(
            People::class,
            LeadParticipant::class,
            'peoples_id',
            'leads_id',
            'id',
            'id'
        );
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'leads_owner_id', 'id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(LeadReceiver::class, 'leads_receivers_id', 'id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'leads_status_id', 'id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'leads_sources_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeadType::class, 'lead_types_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id', 'id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id', 'id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LeadAttempt::class, 'id', 'leads_id');
    }

    public function isOpen(): bool
    {
        return $this->status < 2;
    }

    public function isActive(): bool
    {
        $statusName = strtolower($this->status()->firstOrFail()->name);

        return $statusName !== 'inactive' && (Str::contains($statusName, 'active') || Str::contains($statusName, 'created'));
    }

    public function close(): void
    {
        $this->leads_status_id = 6; //change to a bete format
        $this->saveOrFail();
    }
}
