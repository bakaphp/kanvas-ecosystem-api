<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Workflow\ActivityStub;
use Workflow\Workflow;

class ZohoLeadOwnerWorkflow extends Workflow
{
    public function execute(
        string $leadId,
        LeadReceiver $receiver,
        AppInterface $app,
        array $params = []
    ): Generator {
        $result = yield ActivityStub::make(
            ZohoLeadOwnerActivity::class,
            $leadId,
            $receiver,
            $app,
            $params
        );

        return $result;
    }
}