<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\KanvasActivity;

class ZohoLeadOwnerActivity extends KanvasActivity
{
    public $tries = 10;

    public function execute(
        string $zohoLeadId,
        LeadReceiver $receiver,
        AppInterface $app,
        array $params = []
    ): array {
        $this->overwriteAppService($app);

        if (! $receiver->rotation()->exists()) {
            return ['Rotation not found'];
        }
        $agent = $receiver->rotation()->first()->getAgent();

        $company = $receiver->company()->firstOrFail();
        $agentOwner = Agent::fromCompany($company)->where('users_id', $agent->getId())->first();

        if (! $agentOwner) {
            return ['Agent not found'];
        }

        $ownerZohoId = $agentOwner->users_linked_source_id;

        $zohoCrm = Client::getInstance($app, $company);

        $zohoData = [
            'Owner' => $ownerZohoId,
            'Sales Rep' => $agentOwner->name,
        ];

        $zohoLead = $zohoCrm->leads->update(
            $zohoLeadId,
            $zohoData
        );

        return [
            'message' => 'Owner updated successfully',
            'lead' => $zohoLead->toArray(),
        ];
    }
}
