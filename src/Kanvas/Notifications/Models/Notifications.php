<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use Awobaz\Compoships\Database\Eloquent\Model;
use Baka\Casts\Json;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
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

    /**
     * Not deleted scope.
     */
    public function scopeAllNotifications(Builder $query, array $args): Builder
    {
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

        return $query->where('users_id', auth()->user()->id)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->where('apps_id', app(Apps::class)->id);
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
     * Mark the notification as read.
     */
    public function markAsRead(): void
    {
        if (! $this->read) {
            $this->forceFill(['read' => 1])->save();
        }
    }
}
