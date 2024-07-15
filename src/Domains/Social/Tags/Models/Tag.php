<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Models;

use Baka\Traits\SlugTrait;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\Models\BaseModel;

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
    protected $table = 'tags';

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

    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();
        return $databaseName . '.tags';
    }
}
