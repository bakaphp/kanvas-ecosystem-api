<?php

namespace Kanvas\Social\Tags\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class TagEntity extends MorphPivot
{
    protected $table = 'tags_entities';

    public $timestamps = true;

    protected $fillable = [
        'tags_id',
        'entity_id',
        'entity_namespace',
        'companies_id',
        'apps_id',
        'users_id',
        'is_deleted',
    ];

    public function entity()
    {
        return $this->morphTo(null, 'entity_namespace', 'entity_id');
    }
}
