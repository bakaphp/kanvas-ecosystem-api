<?php

declare(strict_types=1);

namespace Kanvas\Guild\Rotations\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
class Rotation extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'rotations';
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            Users::class,
            RotationUser::class,
            'rotations_id',
            'id',
            'id',
            'users_id'
        );
    }
}
