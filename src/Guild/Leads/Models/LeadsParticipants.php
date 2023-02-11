<?php
declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Customers\Models\PeoplesRelationships;
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
 *
 */
class LeadsParticipants extends BaseModel
{
    use Searchable;
    use HasCompositePrimaryKeyTrait;

    protected $primaryKey = ['leads_id', 'people_id'];
    protected $table = 'leads_participants';
    protected $guarded = [];

    public function people() : BelongsTo
    {
        return $this->belongsTo(
            Peoples::class,
            'people_id',
            'id'
        );
    }

    public function lead() : BelongsTo
    {
        return $this->belongsTo(
            Leads::class,
            'leads_id',
            'id'
        );
    }

    public function type() : BelongsTo
    {
        return $this->belongsTo(
            PeoplesRelationships::class,
            'participants_types_id',
            'id'
        );
    }
}
