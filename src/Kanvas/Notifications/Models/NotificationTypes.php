<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * NotificationTypes Model.
 *
 * @property int $apps_id
 * @property int $system_modules_id
 * @property int $notification_channel_id
 * @property string $name
 * @property string $key
 * @property string $description
 * @property string $template
 * @property string $icon_url
 * @property int $with_realtime
 * @property int $parent_id
 * @property float $is_published
 */
class NotificationTypes extends BaseModel
{
    use Cachable;
    
    public $table = 'notification_types';

    /**
     * getByName.
     */
    public static function getByName(string $name): self
    {
        return self::where('name', $name)->firstOrFail();
    }

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }
}
