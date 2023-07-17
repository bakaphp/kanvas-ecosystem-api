<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Models;

use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\Topics\Models\Topic;
use Kanvas\Users\Models\Users;
use Laravel\Scout\Searchable;

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
    use SoftDeletes;
    use Searchable;
    use HasFilesystemTrait;

    protected $table = 'users_lists';

    protected $guarded = [
        'files',
    ];

    public function topicsItems(): Attribute
    {
        return Attribute::make(
            get: fn () => Topic::join('entity_topics', 'entity_topics.topics_id', '=', 'topics.id')
                ->join('messages', 'messages.id', '=', 'entity_topics.entity_id')
                ->join('users_lists_messages', 'users_lists_messages.messages_id', '=', 'messages.id')
                ->where('entity_topics.entity_namespace', Message::class)
                ->where('users_lists_messages.users_lists_id', $this->getId())
                ->select('topics.*')
                // ->groupBy('topics.id')
                ->get()
        );
    }

    public function ownAllItems(): Attribute
    {
        return Attribute::make(
            get: fn () => (Message::join('users_lists_messages', 'users_lists_messages.messages_id', '=', 'messages.id')
                ->where('users_lists_messages.users_lists_id', $this->getId())
                ->where('messages.users_id', auth()->user()->getId())
                ->select('messages.*')
                ->count() == $this->items->count()) && $this->items->count() > 0
        );
    }

    public function user(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Users::class, 'users_id');
    }

    public function app(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Apps::class, 'apps_id');
    }

    public function company(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Companies::class, 'companies_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'users_lists_messages', 'users_lists_id', 'messages_id')->orderBy('weight', 'ASC');
    }

    public function followers(): BelongsToMany
    {
        $database = config('database.connections.social.database');

        return $this->setConnection('ecosystem')->belongsToMany(Users::class, $database . '.users_follows', 'entity_id', 'users_id')
            ->where('entity_namespace', self::class);
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
