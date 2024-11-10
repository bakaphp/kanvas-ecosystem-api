<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Internal\Actions\AppUsersNotificationByRoleAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class AppUsersNotificationByRoleActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 2;

    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $appUserNotificationByRoleAction = new AppUsersNotificationByRoleAction($app, $entity, $params);

        return $appUserNotificationByRoleAction->execute();
    }
}
