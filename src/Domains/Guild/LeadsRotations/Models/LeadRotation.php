<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadsRotations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Class Rotation.
 * @deprecated version 2.0
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 */
class LeadRotation extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'rotations';
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function users(): BelongsToMany
    {
        $pivot = (new LeadRotationUser())->getFullTableName();
        return $this->belongsToMany(
            Users::class,
            $pivot,
            'rotations_id',
            'users_id'
        );
    }
}
