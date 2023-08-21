<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadType.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $description
 * @property int $is_active
 * @property int $is_default
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadType extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'leads_types';
    protected $guarded = [];

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
