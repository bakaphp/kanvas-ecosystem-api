<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Generator;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Workflow\ActivityStub;
use Workflow\Workflow;

class ZohoAgentWorkflow extends Workflow
{
    public function execute(AppInterface $app, UserInterface $user): Generator
    {
        $result = yield ActivityStub::make(ZohoAgentActivity::class, $app, $user);

        return $result;
    }
}
