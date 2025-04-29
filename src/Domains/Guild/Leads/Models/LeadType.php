<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadType.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
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
    use UuidTrait;

    protected $table = 'leads_types';
    protected $guarded = [];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'leads_types_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
