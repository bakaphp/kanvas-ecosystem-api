<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use Baka\Casts\Json;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Notifications Model.
 *
 * @property int $users_id
 * @property int $from_users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $system_modules_id
 * @property int $notification_types_id
 * @property int $entity_id
 * @property string $content
 * @property ?string $entity_content = null
 * @property string content_group
 * @property int $read
 * @property string $created_at
 * @property string $updated_at
 * @property string $is_deleted
 */
class Notifications extends BaseModel
{
    public $table = 'notifications';
    // use Cachable;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $casts = [
        'entity_content' => Json::class,
    ];

    public function users(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    public function fromUsers(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'from_users_id');
    }

    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function types(): BelongsTo
    {
        return $this->belongsTo(NotificationTypes::class, 'notification_type_id');
    }

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interactions::class, 'interaction_id');
    }

    /**
     * Not deleted scope.
     */
    public function scopeAllNotifications(Builder $query, array $args): Builder
    {
        $app = app(Apps::class);
        $socialDb = config('database.connections.social.database');
        if (isset($args['whereType'])) {
            $notificationTypeFilter = $args['whereType'];
            $query->whereHas('types', function ($query) use ($notificationTypeFilter) {
                if ($notificationTypeFilter['verb']) {
                    $query->where('verb', $notificationTypeFilter['verb']);
                }

                if ($notificationTypeFilter['event']) {
                    $query->where('event', $notificationTypeFilter['event']);
                }
            });
        }

        if (isset($args['whereSystemModule'])) {
            $systemModuleFilter = $args['whereSystemModule'];
            $query->whereHas('systemModule', function ($query) use ($systemModuleFilter, $app, $socialDb) {
                if ($systemModuleFilter['slugs']) {
                    $systemModuleIds = [];
                    foreach ($systemModuleFilter['slugs'] as $systemModuleSlug) {
                        $notificationSystemModule = SystemModulesRepository::getBySlug($systemModuleSlug, $app);
                        $systemModuleIds[] = $notificationSystemModule->getId();
                        if ($notificationSystemModule->model_name == Message::class && isset($systemModuleFilter['message_type_verb'])) {
                            $query->getQuery()->join($socialDb . '.messages', 'messages.id', '=', 'notifications.entity_id');

                            $messageType = MessagesTypesRepository::getByVerb($systemModuleFilter['message_type_verb'], $app);
                            $query->where($socialDb . '.messages.message_types_id', $messageType->getId());
                        }
                    }
                    $query->whereIn('system_modules_id', $systemModuleIds);
                }
            });
        }

        if (isset($args['whereInteraction']) && $args['whereInteraction']['name']) {
            $interaction = Interactions::getByName($args['whereInteraction']['name'], $app);
            if ($interaction) {
                $query->where('interaction_id', $interaction->getId());
            }
        }

        return $query->where('users_id', auth()->user()->id)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('apps_id', $app->id);
    }

    /**
     * Get the entity related to the notification.
     */
    public function getEntityData(): mixed
    {
        if ($this->entity_content !== null && Str::isJson($this->entity_content)) {
            return $this->entity_content;
        }

        try {
            $systemModule = $this->systemModule()->firstOrFail();
            $modelName = $systemModule->model_name;

            /**
             * @todo cache
             */
            return $modelName::getById($this->entity_id);
        } catch (Throwable $e) {
        }

        return null;
    }

    /**
     * Get entity children if it is a instance of Messages and has parent_id NULL
     */
    public function getEntityChildrenData(): mixed
    {
        try {
            $systemModule = $this->systemModule()->firstOrFail();
            $modelName = $systemModule->model_name;
            $entity = $modelName::getById($this->entity_id);
            
            if ($entity instanceof Message && is_null($entity->parent_id)) {
                return $entity->children;
            }
        } catch (Throwable $e) {
        }

        return null;
    }

    /**
     * Mark the notification as read.
     */
    public function markAsRead(): void
    {
        if (! $this->read) {
            $this->forceFill(['read' => 1])->save();
        }
    }
}
