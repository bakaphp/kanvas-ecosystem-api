<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
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
        $array = $this->toArray();
        $array['items'] = $this->items->toArray();

        // Customize the data array...

        return $array;
    }
}
