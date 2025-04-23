<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Models\BaseModel;
use Override;

/**
 * @property int id
 * @property int apps_id
 * @property int companies_id
 * @property int users_id
 * @property string name
 * @property string slug
 * @property string color
 * @property int status
 * @property int is_feature
 */
class Tag extends BaseModel
{
    use SlugTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    protected $guarded = [];
    protected $table = 'tags';

    public function taggables(): HasMany
    {
        return $this->hasMany(TagEntity::class, 'tags_id');
    }

    public function entities(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'tags_entities', 'tags_id', 'entity_id')
                    ->using(TagEntity::class)
                    ->withPivot('entity_namespace', 'companies_id', 'apps_id', 'users_id', 'is_deleted', 'created_at', 'updated_at');
    }

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName.'.tags';
    }

    public function searchableAs(): string
    {
        //$tag = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $tag = !$this->searchableDeleteRecord() ? $this : $this->find($this->id);
        $app = $tag->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_tag_index') ?? null;

        return config('scout.prefix').($customIndex ?? 'tag_index');
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && !auth()->user()->isAppOwner()) {
            $query->where('company.id', auth()->user()->getCurrentCompany()->getId());
        }

        return $query;
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => $this->id,
            'id'       => $this->id,
            'name'     => $this->name,
            'company'  => [
                'id'   => $this->companies_id,
                'name' => $this->company->name,
            ],
            'user' => [
                'firstname' => $this?->company?->user?->firstname,
                'lastname'  => $this?->company?->user?->lastname,
            ],
            'slug'        => $this->slug,
            'apps_id'     => $this->apps_id,
            'weight'      => $this->weight,
            'status'      => $this->status,
            'is_featured' => $this->is_feature,
            'created_at'  => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
