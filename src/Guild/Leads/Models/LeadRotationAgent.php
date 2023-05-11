<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

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
    use KanvasScopesTrait;
    use NoAppRelationshipTrait;

    protected $table = 'leads_rotations_agents';
    protected $guarded = [];
}
