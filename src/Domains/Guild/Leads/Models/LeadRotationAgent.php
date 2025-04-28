<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Class LeadRotationAgent.
 *
 * @property int $id
 * @property int $leads_rotations_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $phone
 * @property float $percent
 * @property int $hits
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadRotationAgent extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'leads_rotations_agents';
    protected $guarded = [];

    public function users(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
