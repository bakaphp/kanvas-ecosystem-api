<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;
use Webleit\ZohoCrmApi\Enums\UserType;
use Webleit\ZohoCrmApi\Exception\ApiError;
use Webleit\ZohoCrmApi\Models\Model as ZohoModel;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\ZohoCrm;

class ZohoService
{
    protected ZohoCrm $zohoCrm;
    protected string $zohoAgentModule;
    protected ?array $lastCreateAgentRequest = null;
    protected ?Collection $activeZohoUserIds = null;
    protected bool $activeZohoUserIdsLoaded = false;
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

        // Owner is a Zoho lookup to a real org user. A stale/wrong owner_linked_source_id (or default)
        // makes Zoho reject the whole create with INVALID_DATA. Try the owner link first, then the
        // user link, then the company default — and only send Owner when one resolves to a valid user;
        // otherwise omit it so Zoho assigns the API user instead of failing the agent.
        $ownerId = $this->resolveValidOwnerId(
            $agentInfo->owner_linked_source_id,
            $agentInfo->users_linked_source_id,
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
     * Create the agent/vendor record, logging the exact request payload and Zoho's rejection
     * details on failure. Zoho's InvalidDataType message is just the HTTP reason phrase ("Accepted"),
     * which hides WHICH field it refused — $e->details() + the raw body carry the api_name/json_path,
     * so we surface them before rethrowing. See Sentry KANVAS-ECOSYSTEM-2R8.
     */
    private function createZohoAgentRecord(string $module, array $data): object
    {
        try {
            $record = $module === self::DEFAULT_AGENT_MODULE
                ? $this->zohoCrm->agents->create($data)
                : $this->zohoCrm->vendors->create($data);

            if ($record === null) {
                throw new Exception('Zoho returned no record when creating the agent');
            }

            return $record;
        } catch (ApiError $e) {
            Log::error('Zoho agent create rejected', [
                'module' => $module,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'email' => $data['Email'] ?? null,
                'request' => $data,
                'zoho_status' => $e->getCode(),
                'zoho_reason' => $e->getMessage(),
                'zoho_details' => $e->details(),
                'zoho_body' => (string) $e->response()->getBody(),
            ]);

            throw $e;
        }
    }

    public function getLastCreateAgentRequest(): ?array
    {
        return $this->lastCreateAgentRequest;
    }

    /**
     * Resolve an owner id Zoho will accept: the first candidate that maps to a valid org user,
     * then the company default owner, otherwise null (caller should omit the field). Pass the
     * candidates in priority order (e.g. owner_linked_source_id, then users_linked_source_id).
     */
    public function resolveValidOwnerId(int|string|null ...$candidateOwnerIds): ?int
    {
        $candidateOwnerIds[] = $this->company->get(CustomFieldEnum::DEFAULT_OWNER->value);

        return self::pickValidOwnerId($this->getActiveZohoUserIds(), ...$candidateOwnerIds);
    }

    /**
     * Pure selection logic kept network-free so it can be unit tested. A null $activeUserIds means
     * we couldn't load the org's user list — trust the first non-empty candidate rather than
     * stripping a possibly-valid owner from every request.
     */
    public static function pickValidOwnerId(?Collection $activeUserIds, int|string|null ...$candidates): ?int
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || (string) $candidate === '' || (int) $candidate === 0) {
                continue;
            }

            if ($activeUserIds === null || $activeUserIds->contains((string) $candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * Ids of the org's active Zoho users (as strings), or null when the list can't be fetched.
     * Cached per service instance to avoid re-hitting the API for every owner check.
     */
    protected function getActiveZohoUserIds(): ?Collection
    {
        if ($this->activeZohoUserIdsLoaded) {
            return $this->activeZohoUserIds;
        }

        $this->activeZohoUserIdsLoaded = true;

        try {
            $this->activeZohoUserIds = $this->zohoCrm->users
                ->ofType(UserType::ACTIVE)
                ->keys()
                ->map(fn ($id) => (string) $id)
                ->values();
        } catch (Throwable $e) {
            // Expected when the tenant's OAuth token lacks the ZohoCRM.users.READ scope — don't
            // report() it (Sentry noise). null makes owner validation trust the candidate instead.
            Log::warning('Zoho active-users lookup failed; owner validation will trust the candidate', [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->activeZohoUserIds = null;
        }

        return $this->activeZohoUserIds;
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
