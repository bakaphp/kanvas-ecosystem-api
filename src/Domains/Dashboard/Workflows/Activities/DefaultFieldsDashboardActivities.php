<?php

declare(strict_types=1);

namespace Kanvas\Dashboard\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Dashboard\Actions\SetDefaultDashboardFieldAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivities;

class DefaultFieldsDashboardActivities extends KanvasActivities implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        (new SetDefaultDashboardFieldAction($params['company']))->execute();

        return [
            'message' => 'Default fields dashboard activity executed',
        ];
    }
}
