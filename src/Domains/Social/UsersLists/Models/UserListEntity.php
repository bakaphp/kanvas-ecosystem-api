<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class UserListEntity extends MorphPivot
{
    protected $table = 'users_lists_entities';

    public $timestamps = true;

    protected $fillable = [
        'users_lists_id',
        'entity_id',
        'entity_namespace',
        'is_pin',
        'description',
        'is_deleted',
    ];

    protected $connection = 'social';

    public function entity()
    {
        return $this->morphTo('entity', 'entity_namespace', 'entity_id');
    }
}
