<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Guild\Leads\Models\Lead;
use Workflow\ActivityStub;
use Workflow\Workflow;

class ZohoLeadWorkflow extends Workflow
{
    public function execute(AppInterface $app, Lead $lead): Generator
    {
        $result = yield ActivityStub::make(ZohoLeadActivity::class, $app, $lead);

        return $result;
    }
}
