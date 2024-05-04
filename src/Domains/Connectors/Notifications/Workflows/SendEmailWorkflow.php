<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Connectors\Notifications\Workflows\SendEmailActivity;
use Kanvas\Users\Models\Users;
use Workflow\ActivityStub;
use Workflow\Workflow;

class SendEmailWorkflow extends Workflow
{
    public function execute(AppInterface $app, Users $user): Generator
    {
        $result = yield ActivityStub::make(SendEmailActivity::class, $app, $user);

        return $result;
    }
}
