<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Webleit\ZohoCrmApi\Models\Model as ZohoModel;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\ZohoCrm;

class ZohoService
{
    protected ZohoCrm $zohoCrm;
    protected string $zohoAgentModule;
    protected ?array $lastCreateAgentRequest = null;
    private const string DEFAULT_AGENT_MODULE = 'agents';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->zohoCrm = Client::getInstance($app, $company);
        $this->zohoAgentModule = $this->company->get(CustomFieldEnum::ZOHO_AGENT_MODULE->value) ?? self::DEFAULT_AGENT_MODULE;
    }

    public function getAgentByEmail(string $email): object
    {
        return $this->searchAgent('Email', $email);
    }

    public function getAgentByMemberNumber(string $memberNumber): object
    {
        return $this->searchAgent('Member_Number', $memberNumber);
    }

    public function searchAgent(string $field, string $value): object
    {
        if ($this->zohoAgentModule == self::DEFAULT_AGENT_MODULE) {
            $response = $this->zohoCrm->agents->searchRaw('(' . $field . ':equals:' . $value . ')');
        } else {
            $response = $this->zohoCrm->vendors->searchRaw('(' . $field . ':equals:' . $value . ')');
        }

        if (! $response->count()) {
            throw new Exception('No Agent Found for ' . $value);
        }

        return $response->first();
    }

    public function createAgent(UserInterface $user, Agent $agentInfo, ?object $zohoOwnerAgent = null): object
    {
        $zohoAgentModule = $this->company->get(CustomFieldEnum::ZOHO_AGENT_MODULE->value) ?? self::DEFAULT_AGENT_MODULE;

        $data = [
            'Email' => $user->email,
            'Member_Number' => $agentInfo->getMemberNumber(),
            'Sponsor' => ! empty($agentInfo->owner_id) ? (string) $agentInfo->owner_id : '1001',
            'Owner' => ! empty($agentInfo->owner_linked_source_id) ? (int) $agentInfo->owner_linked_source_id : $this->company->get(CustomFieldEnum::DEFAULT_OWNER->value),
            'Account_Type' => 'Standard',
            'Name' => $agentInfo->name,
            'Office_Phone' => str_replace(['+', '-', '(', ')', ' '], '', $user->phone_number ?? ''),
        ];

        $data = $this->applySponsorData($agentInfo, $data);

        if ($zohoAgentModule == self::DEFAULT_AGENT_MODULE) {
            if ($zohoOwnerAgent instanceof Agent && $zohoOwnerAgent->users_linked_source_id) {
                $zohoOwnerAgent = $this->zohoCrm->agents->get($zohoOwnerAgent->users_linked_source_id);
            }

            $data['Lead_Routing'] = $zohoOwnerAgent !== null ? $zohoOwnerAgent->Lead_Routing : (string) $this->company->get('default_lead_routing');

            $this->lastCreateAgentRequest = $data;
            $this->lastCreateAgentRequest['zohoOwnerAgent'] = $zohoOwnerAgent instanceof ZohoModel
                ? $zohoOwnerAgent->getData()
                : $zohoOwnerAgent;

            $zohoAgent = $this->zohoCrm->agents->create($data);
        } else {
            $data['Vendor_Name'] = $agentInfo->name;
            $data['Phone'] = str_replace(['+', '-', '(', ')', ' '], '', $user->phone_number ?? '');
            $data['Inactive'] = 'Active';

            if ($agentInfo->sponsor_user_id !== null) {
                $sponsorAgent = Agent::where('users_id', $agentInfo->sponsor_user_id)
                    ->where('apps_id', $this->app->getId())
                    ->where('companies_id', $this->company->getId())
                    ->where('is_deleted', false)
                    ->where('status_id', 1)
                    ->first();

                if ($sponsorAgent && $sponsorAgent->users_linked_source_id) {
                    $data['Sponsor_Name'] = $sponsorAgent->users_linked_source_id;
                    $data['Sponsor'] = (string) $sponsorAgent->member_id;
                }
            }

            $this->lastCreateAgentRequest = $data;
            $zohoAgent = $this->zohoCrm->vendors->create($data);
        }

        return $zohoAgent;
    }

    public function getLastCreateAgentRequest(): ?array
    {
        return $this->lastCreateAgentRequest;
    }

    public function updateAgent(Agent $agent): object
    {
        $zohoAgentId = $agent->users_linked_source_id;

        $data = [
            'Sponsor' => ! empty($agent->owner_id) ? (string) $agent->owner_id : '1001',
        ];

        $data = $this->applySponsorData($agent, $data);

        if ($this->zohoAgentModule == self::DEFAULT_AGENT_MODULE) {
            return $this->zohoCrm->agents->update($zohoAgentId, $data);
        }

        return $this->zohoCrm->vendors->update($zohoAgentId, $data);
    }

    private function applySponsorData(Agent $agent, array $data): array
    {
        if ($agent->sponsor_user_id === null) {
            return $data;
        }

        $sponsorAgent = Agent::where('users_id', $agent->sponsor_user_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('status_id', 1)
            ->first();

        if ($sponsorAgent && $sponsorAgent->users_linked_source_id) {
            $data['Sponsor_Name'] = $sponsorAgent->users_linked_source_id; //it the zoho agent uuid
            $data['Sponsor'] = (string) $sponsorAgent->member_id;
            $data['Inactive'] = 'Active';
        }

        return $data;
    }

    public function updateAgentMemberNumber(Agent $agent): object
    {
        $zohoAgentId = $agent->users_linked_source_id;

        $data = [
            'Member_Number' => $agent->getMemberNumber(),
        ];

        if ($this->zohoAgentModule == self::DEFAULT_AGENT_MODULE) {
            return $this->zohoCrm->agents->update($zohoAgentId, $data);
        }

        return $this->zohoCrm->vendors->update($zohoAgentId, $data);
    }

    public function getLeadById(string $leadId): Record
    {
        return $this->zohoCrm->leads->get($leadId);
    }

    public function deleteLead(Lead $lead): void
    {
        $zohoLeadId = $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value);
        if ($zohoLeadId) {
            $this->zohoCrm->leads->delete((string) $zohoLeadId);
        }
    }

    public function deleteAgent(Agent $agent): void
    {
        $zohoAgentId = $agent->users_linked_source_id;
        if ($this->zohoAgentModule == self::DEFAULT_AGENT_MODULE) {
            $this->zohoCrm->agents->delete($zohoAgentId);
        } else {
            $this->zohoCrm->vendors->delete($zohoAgentId);
        }
    }
}
