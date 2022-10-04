<?php
declare(strict_types=1);
namespace Kanvas\Notifications\Models;

use Kanvas\Models\BaseModel;

/**
 * Notifications Model.
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
}
