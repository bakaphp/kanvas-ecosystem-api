<?php

declare(strict_types=1);

namespace Kanvas\Apps\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Actions\AppUsersNotificationByRoleAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivities;

class AppUsersNotificationByRoleActivity extends KanvasActivities implements WorkflowActivityInterface
{
    public $tries = 2;

    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $appUserNotificationByRoleAction = new AppUsersNotificationByRoleAction($app, $entity, $params);

        return $appUserNotificationByRoleAction->execute();
    }
}
