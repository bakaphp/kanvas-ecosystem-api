<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Organization.
 *
 * @property int $organizations_id
 * @property int $peoples_id
 * @property string $created_at
 */
class OrganizationPeople extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'organizations_peoples';
    protected $guarded = [];

    protected $attributes = [];
    public $timestamps = false;

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'peoples_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizations_id', 'id');
    }

    /**
     * Not deleted scope.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query;
    }

    public static function addPeopleToOrganization(Organization $organization, People $people): OrganizationPeople
    {
        return self::firstOrCreate([
            'organizations_id' => $organization->getId(),
            'peoples_id' => $people->getId(),
        ]);
    }
}
