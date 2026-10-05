<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * @property int $id
 * @property int $tags_id
 * @property int $entity_id
 * @property string|null $taggable_type
 * @property int $users_id
 */
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

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tags_id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'taggable_type', 'model_name')
            ->where('apps_id', $this->tag?->apps_id);
    }

    public function getSystemModuleNameAttribute(): ?string
    {
        return $this->systemModule?->name;
    }
}
