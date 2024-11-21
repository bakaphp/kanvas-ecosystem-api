<?php

declare(strict_types=1);

namespace Kanvas\Users\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\KanvasActivity;

class AssignToDefaultCompanyActivity extends KanvasActivity
{
    public function execute(Users $user, Apps $app, array $param): void
    {
        $appKey = config('kanvas.app.id');
        $app = AppsRepository::findFirstByKey($appKey);
        $param['company']->associateApp($app);
        $param['company']->associateUserApp(
            $user,
            $app,
            StateEnums::ON->getValue()
        );
    }
}
