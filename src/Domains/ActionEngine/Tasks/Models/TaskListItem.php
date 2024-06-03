<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
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

    public function action(): BelongsTo
    {
        return $this->belongsTo(CompanyAction::class, 'companies_action_id');
    }
}
