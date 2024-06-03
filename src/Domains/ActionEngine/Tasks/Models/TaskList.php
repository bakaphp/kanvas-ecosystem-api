<?php

declare(strict_types=1);

namespace Domains\ActionEngine\Tasks\Models;

use Baka\Traits\UuidTrait;
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
}
