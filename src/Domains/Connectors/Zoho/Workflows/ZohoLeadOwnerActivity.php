<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Workflow\Activity;

class ZohoLeadOwnerActivity extends Activity
{
    use KanvasJobsTrait;
    public $tries = 10;

    /**
     * @param Lead $lead
     */
    public function execute(
        string $zohoLeadId,
        LeadReceiver $receiver,
        AppInterface $app,
        array $params
    ): array {
        $this->overwriteAppService($app);

        if ($receiver->rotation === null) {
            return ['Rotation not found'];
        }

        $agent = $receiver->rotation->getAgent();

        $company = $receiver->company;
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

        return [$zohoLead];
    }
}
