<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Kanvas\Guild\Leads\Factories\LeadFactory;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Models\BaseModel;
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
 * @property int $lead_types_id
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
class Leads extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use NoAppRelationshipTrait;

    protected $table = 'leads';
    protected $guarded = [];

    /**
    * Create a new factory instance for the model.
    *
    * @return \Illuminate\Database\Eloquent\Factories\Factory
    */
    protected static function newFactory()
    {
        return LeadFactory::new();
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(Peoples::class, 'people_id', 'id');
    }

    public function participants(): HasManyThrough
    {
        return $this->hasManyThrough(
            Peoples::class,
            LeadsParticipants::class,
            'peoples_id',
            'leads_id',
            'id',
            'id'
        );
    }
}
