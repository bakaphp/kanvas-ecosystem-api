<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Kanvas\Models\BaseModel;

/**
 * Notifications Model.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class NotificationChannel extends BaseModel
{
    use Cachable;
    
    public $table = 'notification_channels';

    /**
     * getByName.
     */
    public static function getByName(string $name): self
    {
        return self::where('name', $name)->firstOrFail();
    }
}
