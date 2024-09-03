<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Models;

use Baka\Traits\SlugTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Models\BaseModel;
use Laravel\Scout\Searchable;

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
    use Searchable {
        search as public traitSearch;
    }

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

    public function shouldBeSearchable(): bool
    {
        return $this->is_deleted == 0;
    }

    public function searchableAs(): string
    {
        $tag = ! $this->searchableDeleteRecord() ? $this : $this->find($this->id);

        $customIndex = $tag->app ? $tag->app->get('app_custom_tag_index') : null;

        return config('scout.prefix') . ($customIndex ?? 'tag_index');
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! auth()->user()->isAppOwner()) {
            $query->where('company.id', auth()->user()->getCurrentCompany()->getId());
        }

        return $query;
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => $this->id,
            'id' => $this->id,
            'name' => $this->name,
            'company' => [
                'id' => $this->companies_id,
                'name' => $this->company->name,
            ],
            'user' => [
                'firstname' => $this?->company?->user?->firstname,
                'lastname' => $this?->company?->user?->lastname,
            ],
            'slug' => $this->slug,
            'apps_id' => $this->apps_id,
            'weight' => $this->weight,
            'status' => $this->status,
            'is_featured' => $this->is_feature,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
