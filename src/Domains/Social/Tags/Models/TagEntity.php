<?php

namespace Kanvas\Social\Tags\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Kanvas\SystemModules\Models\SystemModules;

class TagEntity extends MorphPivot
{
    protected $table = 'tags_entities';

    public $timestamps = true;

    protected $fillable = [
        'tags_id',
        'entity_id',
        'entity_namespace',
        'taggable_type',
        'companies_id',
        'apps_id',
        'users_id',
        'is_deleted',
    ];

    protected $connection = 'social';

    public function entity()
    {
        return $this->morphTo(null, 'taggable_type', 'entity_id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'taggable_type', 'model_name')->where('apps_id', $this->apps_id);
    }
}
