<?php

declare(strict_types=1);

namespace Kanvas\Integrations\Zoho\Workflows;

use Generator;
use Kanvas\Guild\Leads\Models\Lead;
use Workflow\ActivityStub;
use Workflow\Workflow;

class ZohoLeadWorkflow extends Workflow
{
    public function execute(Lead $lead): Generator
    {
        $result = yield ActivityStub::make(ZohoLeadActivity::class, $lead);

        return $result;
    }
}
