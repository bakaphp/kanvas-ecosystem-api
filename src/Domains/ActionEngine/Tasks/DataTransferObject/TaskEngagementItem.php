<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\DataTransferObject;

use Spatie\LaravelData\Data;

class TaskEngagementItem extends Data
{
    public function __construct(
        public int $leadId,
        public int $taskListItemId
    ) {
    }
}
