<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Casts\Json;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\AccessControlList\Traits\HasPermissions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Factories\MessageFactory;
use Kanvas\Social\Messages\Observers\MessageObserver;
use Kanvas\Social\MessagesComments\Models\MessageComment;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Social\Topics\Models\Topic;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Laravel\Scout\Searchable;
use Nevadskiy\Tree\AsTree;

/**
 *  Class Message
 *  @property int $id
 *  @property int $parent_id
 *  @property string $parent_unique_id
 *  @property string $uuid
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $users_id
 *  @property int $message_types_id
 *  @property string $message
 *  @property int $reactions_count
 *  @property int $comments_count
 *  @property int $total_liked
 *  @property int $total_disliked
 *  @property int $total_view
 *  @property int $is_public
 *  @property int $total_children
 *  @property int $total_saved
 *  @property int $total_shared
 *  @property string|null ip_address
 */
// Company, User and App Relationship is defined in KanvasModelTrait,
#[ObservedBy([MessageObserver::class])]
class Message extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use HasFactory;
    use HasTagsTrait;
    use CascadeSoftDeletes;
    use SoftDeletesTrait;
    use HasPermissions;
    use AsTree;
    use CanUseWorkflow;
    use HasLightHouseCache;
    //use Cachable;
    use HasFilesystemTrait;

    protected $table = 'messages';

    protected $guarded = [
        'uuid',
    ];

    protected $casts = [
        'message' => Json::class,
    ];

    public function getGraphTypeName(): string
    {
        return 'Message';
    }

    protected $cascadeDeletes = ['comments'];

    public const DELETED_AT = 'is_deleted';

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'entity_topics', 'messages_id', 'entity_id')
                ->where('entity_namespace', self::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_messages', 'messages_id', 'channel_id');
    }

    public function messageType(): BelongsTo
    {
        return $this->belongsTo(MessageType::class, 'message_types_id');
    }

    public function appModuleMessage(): HasOne
    {
        return $this->hasOne(AppModuleMessage::class, 'message_id');
    }

    public function users()
    {
        return $this->belongsToMany(Users::class, 'user_messages', 'messages_id', 'users_id');
    }

    public function getMessage(): array
    {
        return (array) $this->message;
    }

    public function entity(): ?Model
    {
        $legacyClassMap = match ($this->appModuleMessage->system_modules) {
            'Gewaer\Models\Leads' => Lead::class,
            'Gewaer\Models\Companies' => Companies::class,
            'Kanvas\Packages\Social\Models\Messages' => Message::class,
            //'Kanvas\Guild\Activities\Models\Activities' => Message::class,
            default => $this->appModuleMessage->system_modules,
        };

        if (! $legacyClassMap) {
            return null;
        }

        return $legacyClassMap::getById($this->appModuleMessage->entity_id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            UserFullTableName::class,
            'users_id',
            'id'
        );
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MessageComment::class, 'message_id');
    }

    public function getMyInteraction(): array
    {
        $userMessage = UserMessage::where('users_id', auth()->user()->id)
            ->where('messages_id', $this->id)
            ->first();

        return [
            'is_liked' => (int) ($userMessage?->is_liked),
            'is_disliked' => (int) ($userMessage?->is_disliked),
            'is_saved' => (int) ($userMessage?->is_saved),
            'is_shared' => (int) ($userMessage?->is_shared),
            'is_reported' => (int) ($userMessage?->is_reported),
        ];
    }

    public function searchableAs(): string
    {
        //$message = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $message = ! $this->searchableDeleteRecord() ? $this : $this->find($this->id);
        $app = $message->app ?? null;

        /**
         * @todo move this to a global behavior
         * in normal search , id is not set, so we need to use global app
         * [null,{"is_deleted":"1970-01-01T00:00:00.000000Z","app":null}] where null is the id record
         */
        if (! isset($this->id)) {
            $app = app(Apps::class);
        }

        $customIndex = $app ? $app->get('app_custom_message_index') : null;

        return config('scout.prefix') . ($customIndex ?? 'message_index');
    }

    public function shouldBeSearchable(): bool
    {
        if ($this->isDeleted()) {
            return false;
        }

        $filterByMessageType = $this->app->get('index_message_by_type');

        return ! $filterByMessageType || $this->messageType->verb === $filterByMessageType;
    }

    public function setPublic(): void
    {
        $this->is_public = 1;
        $this->saveOrFail();
    }

    public function setPrivate(): void
    {
        $this->is_public = 0;
        $this->saveOrFail();
    }

    protected static function newFactory(): Factory
    {
        return MessageFactory::new();
    }

    public function scopeWhereIsPublic(Builder $query): Builder
    {
        return $query->where('is_public', 1);
    }

    public function scopeWhereIsNotPublic(Builder $query): Builder
    {
        return $query->where('is_public', 0);
    }
}
