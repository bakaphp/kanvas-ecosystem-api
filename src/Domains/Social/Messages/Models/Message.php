<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Casts\Json;
use Baka\Support\Str;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Exception;
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
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Factories\MessageFactory;
use Kanvas\Social\Messages\Observers\MessageObserver;
use Kanvas\Social\MessagesComments\Models\MessageComment;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Social\Topics\Models\Topic;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Nevadskiy\Tree\AsTree;
use Override;
use Rennokki\QueryCache\Traits\QueryCacheable;

/**
 *  Class Message.
 *
 * @property int    $id
 * @property int    $parent_id
 * @property string $parent_unique_id
 * @property string $uuid
 * @property int    $apps_id
 * @property int    $companies_id
 * @property int    $users_id
 * @property int    $message_types_id
 * @property string $message
 * @property int    $reactions_count
 * @property int    $comments_count
 * @property int    $total_liked
 * @property int    $total_disliked
 * @property int    $total_view
 * @property int    $is_public
 * @property int    $is_premium
 * @property int    $total_children
 * @property int    $total_saved
 * @property int    $total_shared
 * @property string|null ip_address
 */
// Company, User and App Relationship is defined in KanvasModelTrait,
#[ObservedBy([MessageObserver::class])]
class Message extends BaseModel
{
    use UuidTrait;
    use DynamicSearchableTrait;
    use HasFactory;
    use HasTagsTrait;
    use CascadeSoftDeletes;
    use SoftDeletesTrait;
    use HasPermissions;
    use AsTree;
    use CanUseWorkflow;
    use HasLightHouseCache;
    use HasFilesystemTrait;
    use QueryCacheable;

    protected $table = 'messages';
    public $cacheFor = null;
    public $cacheDriver = 'redis';
    protected static $flushCacheOnUpdate = true;

    protected $guarded = [
        'uuid',
    ];

    protected $casts = [
        'message'          => Json::class,
        'message_types_id' => 'integer',
        'is_public'        => 'integer',
        'is_deleted'       => 'boolean',
    ];

    #[Override]
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
        /**
         * why? wtf ?
         * because we have a app running that using incorrect json format so we need to handle it.
         *
         * @todo remove this once we are sure all apps are using the correct json format
         */
        $value = $this->getRawOriginal('message');

        if (! is_string($value)) {
            return [];
        }

        // First check if it's already valid JSON
        if (Str::isJson($value)) {
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                //if true means the json most likely is a string like this "{\"description\":\"test\"}"
                $value = substr(stripslashes($value), 1, -1);
            }

            return json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    public function entity(): ?Model
    {
        if (! $this->appModuleMessage) {
            return null;
        }

        $legacyClassMap = SystemModules::convertLegacySystemModules($this->appModuleMessage->system_modules);

        return $legacyClassMap::getById($this->appModuleMessage->entity_id);
    }

    #[Override]
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

    public function getMyInteraction(?UserInterface $user = null): array
    {
        $user = $user ?? auth()->user();
        $userMessage = UserMessage::where('users_id', $user->id)
            ->where('messages_id', $this->id)
            ->first();

        return [
            'is_liked'     => (int) $userMessage?->is_liked,
            'is_disliked'  => (int) $userMessage?->is_disliked,
            'is_saved'     => (int) $userMessage?->is_saved,
            'is_shared'    => (int) $userMessage?->is_shared,
            'is_reported'  => (int) $userMessage?->is_reported,
            'is_purchased' => (int) $userMessage?->is_purchased,
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

        return config('scout.prefix').($customIndex ?? 'message_index');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        if ($this->isDeleted() || ! $this->isPublic()) {
            return false;
        }

        if ($this->app->get('message_disable_searchable')) {
            return false;
        }

        $filterByMessageType = $this->app->get('index_message_by_type');

        return ! $filterByMessageType || $this->messageType->verb === $filterByMessageType;
    }

    public function isPublic(): bool
    {
        return (bool) $this->is_public;
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

    public function setLock(): void
    {
        $this->is_locked = 1;
        $this->saveOrFail();
    }

    public function setUnlock(): void
    {
        $this->is_locked = 0;
        $this->saveOrFail();
    }

    public function isLocked(): bool
    {
        //For now lets make sure all that all messages not linked with orders are unlocked.
        if ((! $this->appModuleMessage->exist()) || (! $this->appModuleMessage->hasEntityOfClass(Order::class))) {
            $this->setUnlock();

            return (bool) $this->is_locked;
        }

        $orderEntity = $this->appModuleMessage->entity;
        if ($this->is_locked && $orderEntity->isFullyCompleted()) {
            $this->setUnlock();
        }

        if ($this->is_locked) {
            throw new Exception('Message content is locked');
        }

        return (bool) $this->is_locked;
    }

    public function getUniqueId(): string
    {
        return (string) $this->verb.'-'.(string) $this->visitor_id;
    }

    public static function getUserMessageCountInTimeFrame(
        int $userId,
        Apps $app,
        int $hours,
        ?int $messageTypesId = null,
        bool $getChildrenCount = false
    ): int {
        return self::fromApp($app)
        ->where('users_id', $userId)
        ->when($messageTypesId, fn ($query) => $query->where('message_types_id', $messageTypesId))
        ->where('created_at', '>=', Carbon::now()->subHours($hours))
        ->when($getChildrenCount, fn ($query) => $query->whereNotNull('parent_id'), fn ($query) => $query->whereNull('parent_id'))
        ->count();
    }

    /**
     * The Typesense schema to be created for the Message model.
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name'   => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name'     => 'parent_id',
                    'type'     => 'int64',
                    'optional' => true,
                    'facet'    => true,
                ],
                [
                    'name'     => 'parent_unique_id',
                    'type'     => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name'  => 'companies_id',
                    'type'  => 'int64',
                    'facet' => true,
                ],
                [
                    'name'  => 'users_id',
                    'type'  => 'int64',
                    'facet' => true,
                ],
                [
                    'name'  => 'message_types_id',
                    'type'  => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'message',
                    'type' => 'object',
                ],
                [
                    'name'     => 'message_text',
                    'type'     => 'string',
                    'optional' => true,
                ],
                [
                    'name'     => 'reactions_count',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'comments_count',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'total_liked',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'total_disliked',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'total_view',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'total_children',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'total_saved',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'total_shared',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
                [
                    'name'  => 'is_public',
                    'type'  => 'bool',
                    'facet' => true,
                ],
                [
                    'name'     => 'is_premium',
                    'type'     => 'bool',
                    'facet'    => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'is_locked',
                    'type'     => 'bool',
                    'facet'    => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'ip_address',
                    'type'     => 'string',
                    'optional' => true,
                ],
                [
                    'name'     => 'user',
                    'type'     => 'object',
                    'optional' => true,
                ],
                [
                    'name'     => 'message_type',
                    'type'     => 'object',
                    'optional' => true,
                ],
                [
                    'name'     => 'topics',
                    'type'     => 'object[]',
                    'optional' => true,
                ],
                [
                    'name'     => 'channels',
                    'type'     => 'object[]',
                    'optional' => true,
                ],
                [
                    'name'     => 'files',
                    'type'     => 'object[]',
                    'optional' => true,
                ],
                [
                    'name'     => 'tags',
                    'type'     => 'string[]',
                    'facet'    => true,
                    'optional' => true,
                ],
                [
                    'name'     => 'entity',
                    'type'     => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'sort' => true,
                ],
                [
                    'name'     => 'updated_at',
                    'type'     => 'int64',
                    'sort'     => true,
                    'optional' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields'  => true,
        ];
    }
}
