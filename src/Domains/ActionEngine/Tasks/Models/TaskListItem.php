<?php

declare(strict_types=1);

namespace Domains\ActionEngine\Tasks\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class Tasks.
 *
 * @property int $id
 * @property int $task_list_id
 * @property int $companies_action_id
 * @property string $name
 * @property string $config
 * @property string $status
 * @property float $weight
 */
class TaskListItem extends BaseModel
{
    use UuidTrait;

    protected $table = 'company_task_list_items';
    protected $guarded = [];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }
}
