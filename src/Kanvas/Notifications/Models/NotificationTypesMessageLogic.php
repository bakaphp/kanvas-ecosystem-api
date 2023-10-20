<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * NotificationTypesMessageLogic Model.
 *
 * @property int $apps_id
 * @property int $messages_type_id
 * @property int $notifications_type_id
 * @property string $logic
 * @property string $created_at
 * @property string $updated_at
 * @property string $is_deleted
 */
class NotificationTypesMessageLogic extends BaseModel
{
    // use Cachable;

    public $table = 'notification_types_message_logic';

    public $fillable = [];
}
