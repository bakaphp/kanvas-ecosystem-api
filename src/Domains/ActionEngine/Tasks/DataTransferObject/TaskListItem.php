<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\DataTransferObject;

use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Spatie\LaravelData\Data;

class TaskListItem extends Data
{
    public function __construct(
        public readonly TaskList $taskList,
        public readonly CompanyAction $companyAction,
        public readonly string $name,
        public readonly ?string $status = null,
        public readonly ?array $config = null,
        public readonly float $weight = 0,
    ) {
    }
}
