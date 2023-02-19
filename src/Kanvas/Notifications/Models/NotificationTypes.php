<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Models;

use Kanvas\Models\BaseModel;

/**
 * Notifications Model.
 *
 * @property int $apps_id
 * @property int $system_modules_id
 * @property string $name
 * @property string $key
 * @property string $description
 * @property ?string $template = null
 * @property string $icon_url
 * @property int $with_realtime
 * @property int $parent_id
 * @property float $is_published
 *
 */
class NotificationTypes extends BaseModel
{
    public $table = 'notification_types';

    public $fillable = [
        'apps_id',
        'system_modules_id',
        'name',
        'key',
        'description',
        'template',
        'icon_url',
        'with_realtime',
        'parent_id',
        'is_published'
    ];

    /**
     * getByName.
     *
     * @param  string $name
     *
     * @return self
     */
    public static function getByName(string $name): self
    {
        return self::where('name', $name)->firstOrFail();
    }

    public function hasEmailTemplate(): bool
    {
        return ! empty($this->template);
    }
}
