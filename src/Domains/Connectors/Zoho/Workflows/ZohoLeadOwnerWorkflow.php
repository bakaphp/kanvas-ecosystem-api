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
        int $receiverId,
        AppInterface $app,
        array $params = []
    ): Generator {
        $result = yield ActivityStub::make(
            ZohoLeadOwnerActivity::class,
            $leadId,
            $receiverId,
            $app,
            $params
        );

        return $result;
    }
}
