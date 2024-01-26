<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class ZohoAgentActivity extends Activity implements WorkflowActivityInterface
{
    public function execute(Model $user, AppInterface $app, array $params): array
    {
        if (! isset($params['company'])) {
            throw new Exception('Company is required');
        }

        $company = $params['company'];
        $usesAgentsModule = $company->get(CustomFieldEnum::ZOHO_HAS_AGENTS_MODULE->value);
        if (! $usesAgentsModule) {
            return ['No Agent Module'];
        }

        $zohoService = new ZohoService($app, $company);

        try {
            $record = $zohoService->getAgentByEmail($user->email);
        } catch(Exception $e) {
            $newAgentRecord = $this->createAgent($app, $zohoService, $user, $company);
            $record = $newAgentRecord['zohoAgent'];
            $newAgent = $newAgentRecord['agent'];
        }

        $owner = $record->Owner;
        $name = $record->Name ?? $newAgent->name;
        $memberNumber = $record->Member_Number ?? $newAgent->member_id;
        $zohoId = $record->id;
        $ownerAgent = null;

        try {
            $ownerUser = Users::getByEmail($owner['email']);
            $ownerAgent = Agent::where('users_id', $ownerUser->getId())->fromCompany($company)->firstOrFail();
        } catch (Exception $e) {
        }
        $ownerId = $ownerAgent ? $ownerAgent->member_id : 1001;

        $agentUpdateData = [
            'name' => $name,
            'users_linked_source_id' => $zohoId,
            'member_id' => $memberNumber,
            'owner_id' => $ownerId ?? 1001,
        ];

        if ($owner) {
            $agentUpdateData['owner_linked_source_id'] = $owner['id'];
        }

        Agent::updateOrCreate([
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
        ], $agentUpdateData);
        $user->set('member_number_' . $company->getId(), $memberNumber);

        return [
            'member_id' => $memberNumber,
            'zohoId' => $zohoId,
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
        ];
    }

    protected function createAgent(AppInterface $app, ZohoService $zohoService, UserInterface $user, Companies $company): array
    {
        try {
            $userInvite = UsersInvite::fromCompany($company)->fromApp($app)->where('email', $user->email)->firstOrFail();
            $agentOwner = Agent::fromCompany($company)->where('users_id', $userInvite->users_id)->firstOrFail();
            $ownerInfo = $zohoService->getAgentByMemberNumber((string) $agentOwner->member_id);

            $ownerId = $ownerInfo->Owner['id'];
            $ownerMemberNumber = $ownerInfo->Member_Number;
        } catch(Exception $e) {
            $agentOwner = null;
            $ownerMemberNumber = null;
        }

        $companyDefaultOwnerSourceId = $company->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value);
        $companyDefaultOwnerMemberId = $company->get(CustomFieldEnum::ZOHO_USER_OWNER_MEMBER_NUMBER->value) ?? 1001;
        $agent = new Agent();
        $agent->users_id = $user->getId();
        $agent->companies_id = $company->getId();
        $agent->name = $user->firstname . ' ' . $user->lastname;
        $agent->member_id = Agent::getNextAgentNumber($company);
        $agent->owner_id = $ownerMemberNumber ?? $companyDefaultOwnerMemberId;
        $agent->owner_linked_source_id = $ownerId ?? $companyDefaultOwnerSourceId;
        $agent->saveOrFail();

        //create in zoho
        $zohoAgent = $zohoService->createAgent($user, $agent, $agentOwner);

        $agent->users_linked_source_id = $zohoAgent->id;
        $agent->saveOrFail();

        return [
            'agent' => $agent,
            'zohoAgent' => $zohoAgent,
        ];
    }
}
