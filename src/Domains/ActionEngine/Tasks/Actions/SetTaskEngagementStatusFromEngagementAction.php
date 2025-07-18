<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Kanvas\ActionEngine\Engagements\Models\Engagement;

class SetTaskEngagementStatusFromEngagementAction
{
    public function __construct(
        protected Engagement $engagement,
        protected string $status,
    ) {
    }

    public function execute(): array
    {
        return [
            'success' => ! empty($results),
            'results' => $results,
            'message' => 'Task engagement status updated',
        ];
    }
}
