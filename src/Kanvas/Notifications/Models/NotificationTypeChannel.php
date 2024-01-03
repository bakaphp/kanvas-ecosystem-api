<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Kanvas\Templates\Models\Templates;

/**
 * NotificationTypeChannel Model.
 *
 * @property int $id
 * @property int $notification_type_id
 * @property int $notification_channel_id
 * @property string $template_id
 */
class NotificationTypeChannel extends BaseModel
{
    // use Cachable;

    public $table = 'notification_type_channels';

    public $fillable = [
        'notification_type_id',
        'notification_channel_id',
        'template_id',
    ];

    public function notificationType(): BelongsTo
    {
        return $this->belongsTo(NotificationTypes::class, 'notification_type_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Templates::class, 'template_id', 'id');
    }
}
