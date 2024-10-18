<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Users\Models\Users;
use Workflow\ActivityStub;
use Workflow\Workflow;

class SendPushNotificationWorkflow extends Workflow
{
    public function execute(AppInterface $app, Users $user): Generator
    {
        $result = yield ActivityStub::make(SendPushNotificationActivity::class, $app, $user);

        return $result;
    }
}
