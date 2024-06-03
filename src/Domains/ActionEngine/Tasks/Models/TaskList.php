<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class Tasks.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $config
 */
class TaskList extends BaseModel
{
    use UuidTrait;

    protected $table = 'company_task_list';
    protected $guarded = [];

    protected $casts = [
        'config' => Json::class,
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(TaskListItem::class, 'task_list_id')->orderBy('weight');
    }
}
