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
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Categories\Traits\HasCategoriesTrait;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Factories\MessageFactory;
use Kanvas\Social\Messages\Observers\MessageObserver;
use Kanvas\Social\MessagesComments\Models\MessageComment;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Social\Topics\Models\Topic;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Nevadskiy\Tree\AsTree;
use Override;

/**
 *  Class Message
 *  @property int $id
 *  @property int $parent_id
 *  @property string $parent_unique_id
 *  @property string $uuid
 *  @property int $apps_id
 *  @property int $companies_id
 *  @property int $users_id
 *  @property int|null $people_id
 *  @property int $message_types_id
 *  @property string|array $message
 *  @property string|null $sender_type
 *  @property string $slug
 *  @property int $reactions_count
 *  @property int $comments_count
 *  @property int $total_liked
 *  @property int $total_disliked
 *  @property int $total_view
 *  @property int $is_public
 *  @property int $is_locked
 *  @property int $is_premium
 *  @property int $total_children
 *  @property int $total_saved
 *  @property int $total_shared
 *  @property string|null ip_address
 *  @property bool $is_un_response
 *  @property int|null $response_message_id
 */
// Company, User and App Relationship is defined in KanvasModelTrait,
#[ObservedBy([MessageObserver::class])]
class Message extends BaseModel
{
    use UuidTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use HasFactory;
    use HasTagsTrait;
    use CascadeSoftDeletes;
    use SoftDeletesTrait;
    use HasPermissions;
    use AsTree;
    use CanUseWorkflow;
    use HasLightHouseCache;
    use HasFilesystemTrait;
    use HasCategoriesTrait;

    protected $table = 'messages';

    protected $guarded = [
        'uuid',
    ];

    protected $casts = [
        'message' => Json::class,
        'message_types_id' => 'integer',
        'people_id' => 'integer',
        'is_public' => 'integer',
        'is_deleted' => 'boolean',
        'is_un_response' => 'boolean',
        'response_message_id' => 'integer',
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

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function isCommunicationMessage(): bool
    {
        return ChannelCategoryEnum::isCommunicationVerb(
            $this->messageType?->verb
        );
    }

    public function appModuleMessage(): HasOne
    {
        return $this->hasOne(AppModuleMessage::class, 'message_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Users::class, 'user_messages', 'messages_id', 'users_id');
    }

    public function childrenByType(string $verb): HasMany
    {
        $messageTypeId = MessagesTypesRepository::getByVerb($verb, $this->app)->getId();

        return $this->hasMany(static::class, $this->getParentKeyName())
        ->where('message_types_id', $messageTypeId)
        ->where('is_public', 1)
        ->where('is_deleted', 0);
    }

    public function getMessage(): array
    {
        /**
         * why? wtf ?
         * because we have a app running that using incorrect json format so we need to handle it
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

            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Message text regardless of body shape — {content}/{text} object, raw string, or
     * double-encoded JSON. Use this, not getMessage(), which returns [] for non-object bodies.
     */
    public function contentText(): string
    {
        $payload = $this->getMessage();
        foreach (['content', 'text', 'message', 'body'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $raw = $this->message;
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        $original = $this->getRawOriginal('message');
        if (is_string($original) && $original !== '') {
            $decoded = json_decode($original);

            return is_string($decoded) ? $decoded : $original;
        }

        return '';
    }

    /**
     * Attachment URLs on this message, split by how far they travel: images ride natively on every
     * agent backend; audio/PDF/doc ride natively only on the in-process backends (Neuron, Laravel).
     * Single source of truth for the agent channel responders and the @mention job — each was
     * duplicating this loop.
     *
     * @return array{images: list<string>, documents: list<string>}
     */
    public function attachmentUrls(): array
    {
        $images = [];
        $documents = [];

        foreach ($this->files as $file) {
            $url = (string) $file->url;
            if ($url === '') {
                continue;
            }

            if ($file->mediaType()->isImage()) {
                $images[] = $url;
            } else {
                $documents[] = $url;
            }
        }

        return ['images' => $images, 'documents' => $documents];
    }

    public function addMessage(array $message): void
    {
        $this->message = array_merge($this->getMessage(), $message);
        $this->saveOrFail();
    }

    public function addEntity(Model $entity): void
    {
        $this->appModuleMessage()->create([
            'entity_id' => $entity->getId(),
            'apps_id' => $this->apps_id,
            'message_types_id' => $this->message_types_id,
            'companies_id' => $this->companies_id,
            'system_modules' => get_class($entity),
        ]);
    }

    public function entity(): ?Model
    {
        if (! $this->appModuleMessage) {
            return null;
        }

        $legacyClassMap = SystemModules::convertLegacySystemModules($this->appModuleMessage->system_modules);

        return $legacyClassMap::getById($this->appModuleMessage->entity_id);
    }

    public function engagement(): HasOne
    {
        return $this->hasOne(
            Engagement::class,
            'message_id',
            'id'
        );
    }

    public function getEngagement(): Engagement
    {
        return $this->engagement()->firstOrFail();
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
            'is_liked' => (int) ($userMessage?->is_liked),
            'is_disliked' => (int) ($userMessage?->is_disliked),
            'is_saved' => (int) ($userMessage?->is_saved),
            'is_shared' => (int) ($userMessage?->is_shared),
            'is_reported' => (int) ($userMessage?->is_reported),
            'is_purchased' => (int) ($userMessage?->is_purchased),
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

    /**
     * Scope to control cross-company message visibility.
     *
     * By default messages are public across all companies within the app (current behavior).
     * Set app setting 'restrict_messages_by_company' to true to filter messages
     * by the current user's company (company-scoped feed behavior).
     */
    public function scopeCompanyVisibility(Builder $query): Builder
    {
        if (app()->bound(AppKey::class) && ! app()->bound(CompaniesBranches::class)) {
            return $query;
        }

        $app = app(Apps::class);

        if ($app->get('restrict_messages_by_company')) {
            $user = auth()->user();

            return $query->where($this->getTable() . '.companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
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

    public function setPremium(): void
    {
        $this->is_premium = 1;
        $this->saveOrFail();
    }

    public function isPremium(): bool
    {
        return (bool) $this->is_premium;
    }

    public function setNotPremium(): void
    {
        $this->is_premium = 0;
        $this->saveOrFail();
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }

    public function getUniqueId(): string
    {
        return (string) $this->verb . '-' . (string) $this->visitor_id;
    }

    public static function getUserMessageCountInTimeFrameBuilder(
        int $userId,
        Apps $app,
        int $hours,
        ?int $messageTypesId = null,
        bool $getChildrenCount = false,
        ?array $messageJsonFilters = null
    ): Builder {
        return self::fromApp($app)
            ->where('users_id', $userId)
            ->when($messageTypesId, fn ($query) => $query->where('message_types_id', $messageTypesId))
            ->where('created_at', '>=', Carbon::now()->subHours($hours))
            ->when($getChildrenCount, fn ($query) => $query->whereNotNull('parent_id'), fn ($query) => $query->whereNull('parent_id'))
            ->when($messageJsonFilters, function ($query) use ($messageJsonFilters) {
                foreach ($messageJsonFilters as $jsonPath => $value) {
                    if (is_array($value)) {
                        // For multiple values, use whereIn with JSON path
                        $query->whereIn("message->{$jsonPath}", $value);
                    } else {
                        // For single value, use where with JSON path
                        $query->where("message->{$jsonPath}", $value);
                    }
                }
            })
            ->withTrashed();
    }

    public function toSearchableArray(): array
    {
        $this->loadMissing(['user', 'messageType']);

        $data = [
            'objectID' => $this->uuid,
            ...$this->toArray(),
            'id' => (string) $this->id, // Typesense requires the document id to be a string
            'message' => $this->normalizeMessageForSearch(), // schema declares `message` as object; legacy string/list bodies get rejected otherwise
            'message_text' => $this->contentText(),
            'user' => [
                'id' => $this->users_id,
                'name' => trim(($this->user->firstname ?? '') . ' ' . ($this->user->lastname ?? '')),
                'displayname' => $this->user->displayname,
            ],
            'message_type' => $this->messageType ? [
                'id' => $this->messageType->id,
                'name' => $this->messageType->name,
                'verb' => $this->messageType->verb,
            ] : null,
            'is_public' => (bool) $this->is_public,
            'is_premium' => (bool) $this->is_premium,
            'is_locked' => (bool) $this->is_locked,
            'is_deleted' => (bool) $this->is_deleted,
            // Typesense schema declares these as int64; toArray() emits ISO-8601 strings, so cast to Unix timestamps
            'created_at' => $this->created_at?->getTimestamp(),
            'updated_at' => $this->updated_at?->getTimestamp(),
        ];

        // Add parent reference for child messages
        if ($this->parent_id) {
            $data['parent'] = [
                'id' => $this->parent_id,
                'uuid' => $this->parent?->uuid,
            ];
        }

        // Add children summary for parent messages
        if (! $this->parent_id) {
            $data['children'] = $this->getSearchableChildrenSummary();
            $data['has_children'] = $this->total_children > 0;
        }

        if ($this->isAlgolia()) {
            $data = $this->fitWithinAlgoliaRecordLimit($data);
        }

        return $data;
    }

    /**
     * A message body can be an entire LLM answer, and Algolia rejects the whole batch over one
     * oversized record (Sentry KANVAS-ECOSYSTEM-5TG), so trim instead of losing the batch.
     * Thread previews go first; the body shrinks in stages after that — a shortened body still
     * matches searches, and the full text is read from the DB on display anyway.
     */
    protected function fitWithinAlgoliaRecordLimit(array $message): array
    {
        return $this->trimToAlgoliaLimit($message)
            ->truncateStrings('children', [500, 200])
            ->forget('children')
            // `message` duplicates `message_text`; shrink the object copy first since search
            // matches on the text field.
            ->truncateStrings('message', [2000, 500, 200])
            ->limitString('message_text', 10000, 2000, 500)
            // Whatever is left is a relation toArray() serialized in — never leave the record
            // over budget, the batch it rides in carries other messages.
            ->truncateEverything(500, 200, 100)
            ->get();
    }

    private function getSearchableChildrenSummary(): array
    {
        return $this->children()
            ->where('is_public', 1)
            ->select(['id', 'uuid', 'message', 'created_at', 'users_id'])
            ->with('user:id,firstname,lastname,displayname')
            ->limit(5) // Increased from 3 for better context
            ->get()
            ->map(fn ($child) => [
                'id' => $child->id,
                'uuid' => $child->uuid,
                'message' => $child->normalizeMessageForSearch(),
                'created_at' => $child->created_at->toIso8601String(),
                'user' => [
                    'id' => $child->users_id,
                    'name' => trim(($child->user->firstname ?? '') . ' ' . ($child->user->lastname ?? '')),
                    'displayname' => $child->user->displayname,
                ],
            ])->toArray();
    }

    /**
     * Bodies are inconsistent (plain string, list, or object — see getMessage()) and Typesense
     * rejects the batch on any shape its collection doesn't hold, so match the collection: the
     * declared object, or flat text on collections that auto-typed `message` as a string
     * (Sentry KANVAS-ECOSYSTEM-628). stdClass so an empty body encodes as `{}`, not `[]`.
     */
    private function normalizeMessageForSearch(): object|string
    {
        $message = $this->getMessage();

        if (array_is_list($message)) {
            $text = $this->contentText();
            $message = $text !== '' ? ['content' => $text] : [];
        }

        return $this->searchIndexRejectsObjectField('message')
            ? $this->contentText()
            : (object) $message;
    }

    /**
     * The Typesense schema to be created for the Message model.
     * @psalm-suppress MissingTemplateParam
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name' => 'parent_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'parent_unique_id',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'users_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'message_types_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'message',
                    'type' => 'object',
                ],
                [
                    'name' => 'message_text',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'reactions_count',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'comments_count',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'total_liked',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'total_disliked',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'total_view',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'total_children',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'total_saved',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'total_shared',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'is_public',
                    'type' => 'bool',
                    'facet' => true,
                ],
                [
                    'name' => 'is_premium',
                    'type' => 'bool',
                    'facet' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'is_locked',
                    'type' => 'bool',
                    'facet' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'ip_address',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'user',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'message_type',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'topics',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'channels',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'files',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'tags',
                    'type' => 'string[]',
                    'facet' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'entity',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'sort' => true,
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'int64',
                    'sort' => true,
                    'optional' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * Override search to enforce app and company visibility scoping.
     * @search bypasses @paginate scopes, so this is the only place to enforce multi-tenancy during search.
     * Respects the same 'restrict_messages_by_company' app setting as scopeCompanyVisibility.
     */
    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);
        $searchQuery = self::traitSearch($query, $callback)->where('apps_id', $app->getId());

        if ($searchQuery->model->isTypesense()) {
            $searchQuery->options([
                'query_by' => 'message_text',
            ]);
        }

        if (app()->bound(AppKey::class) && ! app()->bound(CompaniesBranches::class)) {
            return $searchQuery;
        }

        $user = auth()->user();

        if ($user instanceof UserInterface && $app->get('restrict_messages_by_company')) {
            $searchQuery->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $searchQuery;
    }
}
