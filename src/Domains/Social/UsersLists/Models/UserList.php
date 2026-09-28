<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;

/**
 *  class UserList
 *  @property int $id
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $users_id
 *  @property string $name
 *  @property string $slug
 *  @property string $description
 *  @property bool $is_public
 *  @property bool $is_default
 */
class UserList extends BaseModel
{
    use SlugTrait;
    use KanvasAppScopesTrait;
    use SoftDeletesTrait;
    use DynamicSearchableTrait;
    use HasFilesystemTrait;

    protected $table = 'users_lists';

    protected $guarded = [
        'files',
    ];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'users_lists_messages', 'users_lists_id', 'messages_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(UserListEntity::class, 'users_lists_id');
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'users_lists_index_app_' . app(Apps::class)->getId();
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // attributesToArray() instead of toArray() so eager-loaded relations (app carries the
        // app `key`) never reach the index. Typesense also rejects a non-string document `id`.
        $array = $this->attributesToArray();
        $array['items'] = $this->items->toArray();
        $array['objectID'] = (string) $this->id;
        $array['id'] = (string) $this->id;

        return $array;
    }

    /**
     * Scout creates the collection from this. Without it the create call carries a
     * name and nothing else, and Typesense answers `Parameter \`fields\` is required`
     * on every index attempt.
     *
     * Only what is filtered or sorted on is typed; the trailing `.*` absorbs the rest,
     * so a change to toSearchableArray() cannot break indexing.
     *
     * @return array<string, mixed>
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'enable_nested_fields' => true,
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'objectID', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'slug', 'type' => 'string', 'optional' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'apps_id', 'type' => 'int64', 'facet' => true],
                ['name' => 'companies_id', 'type' => 'int64', 'facet' => true, 'optional' => true],
                ['name' => 'users_id', 'type' => 'int64', 'facet' => true, 'optional' => true],
                ['name' => 'is_public', 'type' => 'bool', 'facet' => true, 'optional' => true],
                ['name' => 'is_default', 'type' => 'bool', 'facet' => true, 'optional' => true],
                // Empty for a list with no items, so it cannot be required.
                ['name' => 'items', 'type' => 'object[]', 'optional' => true],
                ['name' => '.*', 'type' => 'auto', 'optional' => true],
            ],
        ];
    }
}
