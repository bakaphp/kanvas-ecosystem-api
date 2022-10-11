<?php

declare(strict_types=1);
namespace Kanvas\Notifications\Settings\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\NotificationsTypes;

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

    /**
     * users
     *
     * @return BelongsTo
     */
    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * apps
     *
     * @return BelongsTo
     */
    public function apps()
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * notificationsTypes
     *
     * @return BelongsTo
     */
    public function notificationsTypes()
    {
        return $this->belongsTo(NotificationsTypes::class, 'notifications_types_id');
    }
}
