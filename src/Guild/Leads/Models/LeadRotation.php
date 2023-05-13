<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadRotation.
 *
 * @property int $id
 * @property int $companies_id
 * @property string $name
 * @property string $leads_rotations_email
 * @property int $hits
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadRotation extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'leads_rotations';
    protected $guarded = [];
}
