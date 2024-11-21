<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivities;

class ZohoAgentActivity extends KanvasActivities implements WorkflowActivityInterface
{
    public $tries = 10;

    public function execute(Model $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        if (! isset($params['company'])) {
            throw new Exception('Company is required');
        }

        $company = $params['company'];
        $usesAgentsModule = $company->get(CustomFieldEnum::ZOHO_HAS_AGENTS_MODULE->value);
        if (! $usesAgentsModule) {
            return ['No Agent Module'];
        }

        $zohoService = new ZohoService($app, $company);
        $newAgentRecord = null;

        try {
            $record = $zohoService->getAgentByEmail($user->email);
        } catch (Exception $e) {
            $newAgentRecord = $this->createAgent($app, $zohoService, $user, $company);
            $record = $newAgentRecord['zohoAgent'];
            $newAgent = $newAgentRecord['agent'];
        }

        $owner = $record->Owner;
        $name = ($record->Name ?? $record->Vendor_Name) ?? $newAgent->name;
        $memberNumber = $record->Member_Number ?? $newAgent->member_id;
        $zohoId = $record->id;
        $ownerAgent = null;

        try {
            $ownerUser = Users::getByEmail($owner['email']);
            $ownerAgent = Agent::where('users_id', $ownerUser->getId())->fromCompany($company)->firstOrFail();
        } catch (Exception $e) {
        }

        $agentUpdateData = [
            'name' => $name,
            'users_linked_source_id' => $zohoId,
            'member_id' => $memberNumber,
        ];

        $companyDefaultOwnerMemberId = $company->get(CustomFieldEnum::ZOHO_USER_OWNER_MEMBER_NUMBER->value) ?? 1001;

        //if the owner is the company default owner, set it
        if ($ownerAgent && $newAgentRecord && $newAgentRecord->member_id == $companyDefaultOwnerMemberId) {
            $agentUpdateData['owner_id'] = $ownerAgent->member_id;
        }

        if ($owner) {
            $agentUpdateData['owner_linked_source_id'] = $owner['id'];
        }

        Agent::updateOrCreate([
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
        ], $agentUpdateData);
        $user->set('member_number_' . $company->getId(), $memberNumber);

        if ($company->get(CustomFieldEnum::ZOHO_DEFAULT_LANDING_PAGE->value)) {
            $user->set('landing_page', $company->get(CustomFieldEnum::ZOHO_DEFAULT_LANDING_PAGE->value), true);
        }

        return [
            'member_id' => $memberNumber,
            'zohoId' => $zohoId,
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
            //'newAgentRecord' => $newAgentRecord ?? [],
        ];
    }

    /**
     * @todo refactor this to a service
     */
    protected function createAgent(AppInterface $app, ZohoService $zohoService, UserInterface $user, Companies $company): array
    {
        $companyDefaultUseRotation = $company->get('agent_use_rotation') ?? false;
        $userInvite = null;

        try {
            $userInvite = UsersInvite::fromCompany($company)->fromApp($app)->where('email', $user->email)->firstOrFail();
            $agentOwner = Agent::fromCompany($company)->where('users_id', $userInvite->users_id)->firstOrFail();
            $ownerInfo = $zohoService->getAgentByMemberNumber((string) $agentOwner->member_id);

            $ownerId = $ownerInfo->Owner['id'];
            $ownerMemberNumber = $ownerInfo->Member_Number;
        } catch (Exception $e) {
            //log the error
            $agentOwner = null;
            $ownerInfo = null;
            $ownerMemberNumber = null;
        }

        $sponsorsPage = $company->get('sponsors_page') ?? [];
        $sponsorsPageLandingPages = $company->get('sponsors_page_landing') ?? [];
        $agentPage = $user->get('agent_website');
        $agentPageUserId = $sponsorsPage[$agentPage] ?? null;
        $agentPageLandingPage = $sponsorsPageLandingPages[$agentPage] ?? null;

        if ($agentPageLandingPage !== null) {
            $user->set('landing_page', $agentPageLandingPage, true);
        }

        if (is_object($userInvite) && $userInvite->get('domain')) {
            //if domain is attached to the invite, set it to the user
            $agentPageLandingPage = $sponsorsPageLandingPages[$userInvite->get('domain')] ?? null;
            $user->set('landing_page', $agentPageLandingPage, true);
        }

        //@todo this is ugly , testing it out
        if ($agentPageUserId !== null) {
            try {
                $agentOwner = Agent::fromCompany($company)->where('users_id', $agentPageUserId)->firstOrFail();
                $ownerMemberNumber = $agentOwner->member_id;
                $ownerId = $agentOwner->users_linked_source_id;
                $ownerInfo = $zohoService->getAgentByMemberNumber((string) $agentOwner->member_id);
            } catch (Exception $e) {
                $agentOwner = null;
                $ownerInfo = null;
                $ownerMemberNumber = null;
            }
        }

        if ($companyDefaultUseRotation !== false && $ownerMemberNumber === null) {
            try {
                $rotation = LeadRotation::getByIdFromCompany($companyDefaultUseRotation, $company);
                $agentUser = $rotation->getAgent();
                $agentOwner = Agent::fromCompany($company)->where('users_id', $agentUser->getId())->firstOrFail();
                $ownerMemberNumber = $agentOwner->member_id;
                $ownerId = $agentOwner->users_linked_source_id;
            } catch (Exception $e) {
            }
        }

        if ((int) $user->get('sponsor_member_number') > 0) {
            try {
                $agentOwner = Agent::fromCompany($company)->where('member_id', $user->get('sponsor_member_number'))->firstOrFail();
                $ownerMemberNumber = $agentOwner->member_id;
                $ownerId = $agentOwner->owner_linked_source_id;
                $ownerInfo = $zohoService->getAgentByMemberNumber((string) $agentOwner->member_id);
            } catch (Exception $e) {
            }
        }

        $companyDefaultOwnerSourceId = $company->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value);
        $companyDefaultOwnerMemberId = $company->get(CustomFieldEnum::ZOHO_USER_OWNER_MEMBER_NUMBER->value) ?? 1001;

        $agent = new Agent();
        $agent->users_id = $user->getId();
        $agent->apps_id = $app->getId();
        $agent->companies_id = $company->getId();
        $agent->name = $user->firstname . ' ' . $user->lastname;
        $agent->member_id = Agent::getNextAgentNumber($company);
        $agent->owner_id = $ownerMemberNumber ?? $companyDefaultOwnerMemberId;
        $agent->owner_linked_source_id = $ownerId ?? $companyDefaultOwnerSourceId;
        $agent->saveOrFail();

        //create in zoho
        $zohoAgent = $zohoService->createAgent($user, $agent, $ownerInfo);

        $agent->users_linked_source_id = $zohoAgent->id;
        $agent->saveOrFail();

        return [
            'agent' => $agent,
            'zohoAgent' => $zohoAgent,
            'agentOwner' => $ownerInfo,
        ];
    }
}
