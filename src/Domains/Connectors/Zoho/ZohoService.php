<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Webleit\ZohoCrmApi\ZohoCrm;

class ZohoService
{
    protected ZohoCrm $zohoCrm;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->zohoCrm = Client::getInstance($app, $company);
    }

    public function getAgentByEmail(string $email): object
    {
        $zohoAgentModule = $this->company->get(CustomFieldEnum::ZOHO_AGENT_MODULE->value) ?? 'agents';

        if ($zohoAgentModule == 'agents') {
            $response = $this->zohoCrm->agents->searchRaw('(Email:equals:' . $email . ')');
        } else {
            $response = $this->zohoCrm->vendors->searchRaw('(Email:equals:' . $email . ')');
        }

        if (! $response->count()) {
            throw new Exception('No Agent Found for ' . $email);
        }

        return $response->first();
    }

    public function getAgentByMemberNumber(string $memberNumber): object
    {
        $zohoAgentModule = $this->company->get(CustomFieldEnum::ZOHO_AGENT_MODULE->value) ?? 'agents';

        if ($zohoAgentModule == 'agents') {
            $response = $this->zohoCrm->agents->searchRaw('(Member_Number:equals:' . $memberNumber . ')');
        } else {
            $response = $this->zohoCrm->vendors->searchRaw('(Member_Number:equals:' . $memberNumber . ')');
        }

        if (! $response->count()) {
            throw new Exception('No Agent Found for ' . $memberNumber);
        }

        return $response->first();
    }

    public function createAgent(UserInterface $user, Agent $agentInfo, ?object $zohoOwnerAgent = null): object
    {
        $zohoAgentModule = $this->company->get(CustomFieldEnum::ZOHO_AGENT_MODULE->value) ?? 'agents';

        if ($zohoAgentModule == 'agents') {
            $zohoAgent = $this->zohoCrm->agents->create([
                'Email' => $user->email,
                'Lead_Routing' => $zohoOwnerAgent ? $zohoOwnerAgent->Lead_Routing : (string) $this->company->get('default_lead_routing'),
                'Member_Number' =>  $agentInfo->getMemberNumber(),
                'Sponsor' => ! empty($agentInfo->owner_id) ? (string) $agentInfo->owner_id : '1001',
                'Owner' => '95641000000215023',//! empty($agentInfo->owner_linked_source_id) ? (int) $agentInfo->owner_linked_source_id : $this->company->get('default_owner'),
                'Account_Type' => 'Standard',
                'Name' => $user->firstname . ' ' . $user->lastname,
                'Office_Phone' => '',
            ]);
        } else {
            $zohoAgent = $this->zohoCrm->vendors->create([
                'Email' => $user->email,
                'Member_Number' => $agentInfo->getMemberNumber(),
                'Sponsor' => (string) $agentInfo->owner_id,
                'Owner' => ! empty($agentInfo->owner_linked_source_id) ? (int) $agentInfo->owner_linked_source_id : 2896936000004020001,
                'Account_Type' => 'Standard',
                'Name' => $user->firstname . ' ' . $user->lastname,
                'Phone' => '',
                'Vendor_Name' => $user->firstname . ' ' . $user->lastname,
            ]);
        }

        return $zohoAgent;
    }
}
