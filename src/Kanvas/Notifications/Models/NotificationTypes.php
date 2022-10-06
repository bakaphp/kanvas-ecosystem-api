<?php
declare(strict_types=1);
namespace Kanvas\Notifications\Models;

use Kanvas\Models\BaseModel;

/**
 * Notifications Model.
 * @property int $apps_id
 * @property int $system_modules_id
 * @property string $name
 * @property string $key
 * @property string $description
 * @property string $template
 * @property string $icon_url
 * @property int $with_realtime
 * @property int $parent_id
 * @property int $is_published
 * @property int $is_published
 * @property float $is_published
 *
 */
class NotificationTypes extends BaseModel
{
    public $table = 'notification_types';

    /**
     * getByName
     *
     * @param  string $name
     * @return self
     */
    public static function getByName(string $name): self
    {
        return self::where('name', $name)->firstOrFail();
    }
}
