<?php

declare(strict_types=1);
namespace Kanvas\Notifications\Settings\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\NotificationsTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UsersNotificationsSettings Model.
 * @property int $users_id
 * @property int $apps_id
 * @property int $notifications_types_id
 * @property int $is_enabled
 * @property string $channels
 * @property string $created_at
 * @property string $updated_at
 * @property string $is_deleted
 */

class UsersNotificationsSettings extends BaseModel
{
    public $table = 'users_notification_settings';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];
    public $incrementing = false;

    /**
     * users
     *
     * @return BelongsTo
     */
    public function users(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * apps
     *
     * @return BelongsTo
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * notificationsTypes
     *
     * @return BelongsTo
     */
    public function notificationsTypes(): BelongsTo
    {
        return $this->belongsTo(NotificationsTypes::class, 'notifications_types_id');
    }

    /**
     * scopeAppUser
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeAppUser(Builder $query): Builder
    {
        $app = app(Apps::class);
        return $query->where('apps_id', $app->id)
            ->where('users_id', auth()->user()->id);
    }

    /**
     * setKeysForSaveQuery
     *
     * @param  Builder $query
     * @return Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query
            ->where('apps_id', $this->getAttribute('apps_id'))
            ->where('users_id', $this->getAttribute('users_id'))
            ->where('notifications_types_id', $this->getAttribute('notifications_types_id'));

        return $query;
    }
}
