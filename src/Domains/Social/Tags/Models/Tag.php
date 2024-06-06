<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Models;

use Kanvas\Social\Models\BaseModel;
use Baka\Traits\SlugTrait;

/**
 * @property int id
 * @property int apps_id
 * @property int companies_id
 * @property int users_id
 * @property string name
 * @property string slug
 * @property string color
 * @property float weight
 */
class Tag extends BaseModel
{
    use SlugTrait;

    protected $guarded = [];

    public function taggables()
    {
        return $this->hasMany(TagEntity::class, 'tags_id');
    }

    public function entities()
    {
        return $this->morphToMany(Tag::class, 'taggable', 'tags_entities', 'tags_id', 'entity_id')
                    ->using(TagEntity::class)
                    ->withPivot('entity_namespace', 'companies_id', 'apps_id', 'users_id', 'is_deleted', 'created_at', 'updated_at');
    }
}
