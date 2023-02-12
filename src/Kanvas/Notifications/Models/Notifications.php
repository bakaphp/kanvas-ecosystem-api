<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

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
 * @property int $read
 * @property string $created_at
 * @property string $updated_at
 * @property string $is_deleted
 * @property string content_group
 *
 */
class Notifications extends BaseModel
{
    public $table = 'notifications';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * users.
     *
     * @return BelongsTo
     */
    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * fromUsers.
     *
     * @return BelongsTo
     */
    public function fromUsers()
    {
        return $this->belongsTo(Users::class, 'from_users_id');
    }

    /**
     * companies.
     *
     * @return BelongsTo
     */
    public function companies()
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * apps.
     *
     * @return BelongsTo
     */
    public function apps()
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * systemModule.
     *
     * @return BelongsTo
     */
    public function systemModule()
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    /**
     * types.
     *
     * @return BelongsTo
     */
    public function types()
    {
        return $this->belongsTo(NotificationTypes::class, 'notification_type_id');
    }

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeAllNotifications(Builder $query): Builder
    {
        return $query->where('users_id', auth()->user()->id)
                ->where('is_deleted', 0)
                ->where('apps_id', app(Apps::class)->id);
    }

    /**
     * Mark the notification as read.
     *
     * @return void
     */
    public function markAsRead()
    {
        if (!$this->read) {
            $this->forceFill(['read' => 1])->save();
        }
    }
}
