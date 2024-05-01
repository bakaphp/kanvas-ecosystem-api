<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Workflow\Activity;

class CreateUserActivity extends Activity
{
    public function execute(Apps $app, Users $user, array $params): void
    {
        
    }
}
