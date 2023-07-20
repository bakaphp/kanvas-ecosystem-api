<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadStatus.
 *
 * @property int $id
 * @property string $name
 * @property int $is_default
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 *
 * @todo add company_id
 */
class LeadStatus extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'leads_status';
    protected $guarded = [];
}
