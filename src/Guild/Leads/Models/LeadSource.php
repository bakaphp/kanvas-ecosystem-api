<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadSource.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $description
 * @property int $leads_types_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadSource extends BaseModel
{
    protected $table = 'leads_sources';
    protected $guarded = [];
}
