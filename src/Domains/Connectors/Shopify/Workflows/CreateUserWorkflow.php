<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Connectors\Shopify\Workflows\Activities\CreateUserActivity;
use Kanvas\Users\Models\Users;
use Workflow\ActivityStub;
use Workflow\Workflow;

class CreateUserWorkflow extends Workflow
{
    public function execute(AppInterface $app, Users $user, array $params): Generator
    {
        return yield ActivityStub::make(CreateUserActivity::class, $app, $user, $params);
    }
}
