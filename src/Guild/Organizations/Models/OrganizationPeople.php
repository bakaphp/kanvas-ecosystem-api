<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Organization.
 *
 * @property int $organizations_id
 * @property int $peoples_id
 */
class OrganizationPeople extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'organizations_peoples';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'peoples_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizations_id', 'id');
    }
}
