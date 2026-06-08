<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Webleit\ZohoCrmApi\Exception\ApiError;
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
            'Account_Type' => 'Standard',
            'Name' => $agentInfo->name,
            'Office_Phone' => str_replace(['+', '-', '(', ')', ' '], '', $user->phone_number ?? ''),
        ];

        // Owner is a Zoho lookup to a real org user. We can't pre-validate it (the tenant's token
        // usually lacks the ZohoCRM.users.READ scope), so send the best candidate — owner link, then
        // user link, then company default — and let createZohoAgentRecord retry without Owner if Zoho
        // rejects it. See Sentry KANVAS-ECOSYSTEM-2R8 / 5RT.
        $ownerId = self::pickFirstOwnerId(
            $agentInfo->owner_linked_source_id,
            $agentInfo->users_linked_source_id,
            $this->company->get(CustomFieldEnum::DEFAULT_OWNER->value),
        );
        if ($ownerId !== null) {
            $data['Owner'] = $ownerId;
        }

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

            $zohoAgent = $this->createZohoAgentRecord($zohoAgentModule, $data);
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
            $zohoAgent = $this->createZohoAgentRecord($zohoAgentModule, $data);
        }

        return $zohoAgent;
    }

    /**
     * Create the agent/vendor record. Owner must be a real Zoho user, but we can't pre-validate it
     * (the tenant token usually lacks the ZohoCRM.users.READ scope), so we let Zoho be the judge:
     * if it rejects the Owner field with INVALID_DATA, drop Owner and retry once — Zoho then assigns
     * the API user as owner instead of failing the whole agent registration. Every rejection is logged
     * with the exact payload + Zoho's api_name/json_path detail, since the bare exception message is
     * just the HTTP reason phrase ("Accepted"). See Sentry KANVAS-ECOSYSTEM-2R8 / 5RT.
     */
    private function createZohoAgentRecord(string $module, array $data): object
    {
        try {
            return $this->postZohoAgentRecord($module, $data);
        } catch (ApiError $e) {
            $body = json_decode((string) $e->response()->getBody(), true);
            $body = is_array($body) ? $body : null;

            $this->logZohoCreateRejection($module, $data, $e, $body);

            if (isset($data['Owner']) && self::isOwnerFieldRejection($body)) {
                unset($data['Owner']);
                $this->lastCreateAgentRequest = $data;

                return $this->postZohoAgentRecord($module, $data);
            }

            throw $e;
        }
    }

    private function postZohoAgentRecord(string $module, array $data): object
    {
        $record = $module === self::DEFAULT_AGENT_MODULE
            ? $this->zohoCrm->agents->create($data)
            : $this->zohoCrm->vendors->create($data);

        if ($record === null) {
            throw new Exception('Zoho returned no record when creating the agent');
        }

        return $record;
    }

    private function logZohoCreateRejection(string $module, array $data, ApiError $e, ?array $body): void
    {
        Log::error('Zoho agent create rejected', [
            'module' => $module,
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'email' => $data['Email'] ?? null,
            'request' => $data,
            'zoho_status' => $e->getCode(),
            'zoho_reason' => $e->getMessage(),
            'zoho_details' => $e->details(),
            'zoho_body' => $body ?? (string) $e->response()->getBody(),
        ]);
    }

    public function getLastCreateAgentRequest(): ?array
    {
        return $this->lastCreateAgentRequest;
    }

    /**
     * First non-empty owner id from the candidates, in priority order. Pure so it can be unit tested.
     */
    public static function pickFirstOwnerId(int|string|null ...$candidates): ?int
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || (string) $candidate === '' || (int) $candidate === 0) {
                continue;
            }

            return (int) $candidate;
        }

        return null;
    }

    /**
     * True when a Zoho create response blames the Owner field for an INVALID_DATA rejection.
     * Reads the raw decoded body (keys preserved) rather than the SDK's flattened details(). Pure.
     */
    public static function isOwnerFieldRejection(?array $body): bool
    {
        foreach ($body['data'] ?? [] as $row) {
            $apiName = is_array($row) ? ($row['details']['api_name'] ?? null) : null;
            if (is_string($apiName) && strtolower($apiName) === 'owner') {
                return true;
            }
        }

        return false;
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
