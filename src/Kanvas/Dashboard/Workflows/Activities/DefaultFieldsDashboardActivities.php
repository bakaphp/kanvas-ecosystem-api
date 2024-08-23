<?php

declare(strict_types=1);

namespace Kanvas\Dashboard\Workflows\Activities;

use Workflow\Activity;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Baka\Contracts\AppInterface;
use Kanvas\Dashboard\Actions\SetDefaultDashboardFieldAction;
use Illuminate\Database\Eloquent\Model;

class DefaultFieldsDashboardActivities extends Activity implements WorkflowActivityInterface
{
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        (new SetDefaultDashboardFieldAction($params['company']))->execute();
        return [
            'message' => 'Default fields dashboard activity executed',
        ];
    }
}
