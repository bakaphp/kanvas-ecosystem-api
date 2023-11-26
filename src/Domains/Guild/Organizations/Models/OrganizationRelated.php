<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class OrganizationRelated.
 *
 * @property int $id
 * @property int $organizations_id
 * @property int $related_organizations_id
 * @property int $organizations_relations_type_id
 */
class OrganizationRelated extends BaseModel
{
    use UuidTrait;
    use NoAppRelationshipTrait;

    protected $table = 'organizations_related';
    protected $guarded = [];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizations_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationRelationshipType::class, 'organizations_relations_type_id', 'id');
    }

    public function relatedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'related_organizations_id', 'id');
    }
}
