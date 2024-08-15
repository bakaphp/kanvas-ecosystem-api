<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Casts\Json;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\AccessControlList\Traits\HasPermissions;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Factories\MessageFactory;
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
 *  @property int $total_view
 *  @property int $total_saved
 *  @property int $total_shared
 */
// Company, User and App Relationship is defined in KanvasModelTrait,
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
    use Cachable;

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
            'is_saved' => (int) ($userMessage?->is_saved),
            'is_shared' => (int) ($userMessage?->is_shared),
            'is_reported' => (int) ($userMessage?->is_reported),
        ];
    }

    public function searchableAs(): string
    {
        $customIndex = $this->app ? $this->app->get('app_custom_message_index') : null;

        return config('scout.prefix') . ($customIndex ?? 'message_index');
    }

    protected static function newFactory(): Factory
    {
        return MessageFactory::new();
    }
}
