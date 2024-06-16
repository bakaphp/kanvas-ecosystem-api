<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Generator;
use Kanvas\Connectors\Internal\Activities\UserCustomFieldActivity;
use Workflow\ActivityStub;
use Workflow\Workflow;

class UserCustomFieldWorkflow extends Workflow
{
    public function execute(AppInterface $app, UserInterface $user, array $params): Generator
    {
        $result = yield ActivityStub::make(UserCustomFieldActivity::class, $app, $user, $params);

        return $result;
    }
}
