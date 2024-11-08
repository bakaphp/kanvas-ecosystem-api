<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Random;
use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

class SyncZohoAgentAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected string $email
    ) {
    }

    public function execute(): Agent
    {
        return DB::transaction(function () {
            // Lock the email row in the users table if it exists
            $existingUser = Users::where('email', $this->email)
                ->lockForUpdate()
                ->first();

            $zohoService = new ZohoService($this->app, $this->company);
            $record = $zohoService->getAgentByEmail($this->email);

            $name = explode(' ', $record->Name);
            $firstName = $name[0];
            $lastName = implode(' ', array_slice($name, 1));
            $memberNumber = $record->Member_Number;
            $zohoId = $record->id;
            $owner = $record->Owner;
            $updatedMemberNumber = false;
            $newMemberNumber = false;

            // Lock the owner user row
            $ownerUser = Users::where('email', $owner['email'])
                ->firstOrFail();

            $ownerAgent = Agent::where('users_id', $ownerUser->getId())
                ->fromCompany($this->company)
                ->first();

            if ($existingUser) {
                $user = $existingUser;
                $user->getAppProfile($this->app);
                $user->firstname = $firstName;
                $user->lastname = $lastName;
                $user->saveOrFail();
            } else {
                // Use unique constraint on email to prevent duplicate creation
                $user = (new CreateUserAction(
                    new RegisterInput(
                        firstname: $firstName,
                        lastname: $lastName,
                        displayname: Random::generateDisplayNameFromEmail($this->email),
                        email: $this->email,
                        password: Str::random(11),
                    )
                ))->execute();
            }

            // Attempt to find an existing agent with the same member number for this company
            $agentWithTheSameMemberNumber = Agent::where([
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'member_id' => $memberNumber,
                ])->lockForUpdate()->first();

            // If the member number is already used by a different user, get a new unique member number
            if ($agentWithTheSameMemberNumber && $agentWithTheSameMemberNumber->users_id !== $user->getId()) {
                $memberNumber = $this->getUniqueAgentMemberNumber();
                $newMemberNumber = true;
            }

            // Find or create an agent record for the user within the company with the verified member number
            $agent = Agent::where([
                'apps_id' => $this->app->getId(),
                'users_id' => $user->getId(),
                'companies_id' => $this->company->getId(),
                'users_linked_source_id' => $zohoId,
                //'member_id' => $memberNumber,
            ])->lockForUpdate()->first();

            if ($agent && $agent->member_id != $memberNumber && ! $newMemberNumber) {
                $updatedMemberNumber = true;
            }

            // Update the agent if it exists, otherwise create a new record
            $agentData = [
                'name' => $record->Name,
                'owner_linked_source_id' => $owner['id'],
                'owner_id' => $ownerAgent ? $ownerAgent->member_id : null,
                'status_id' => 1,
                'updated_at' => now(),
            ];

            if ($agent) {
                if ($updatedMemberNumber) {
                    $agentData['member_id'] = $memberNumber;
                }
                $agent->update($agentData);
            } else {
                $agentData['users_linked_source_id'] = $zohoId;
                $agentData['apps_id'] = $this->app->getId();
                $agentData['users_id'] = $user->getId();
                $agentData['companies_id'] = $this->company->getId();
                $agentData['member_id'] = $memberNumber;

                $agent = Agent::create($agentData);
            }

            return $agent;
        }, 5); // 5 attempts for deadlock cases
    }

    protected function getUniqueAgentMemberNumber(): int
    {
        $currentMaxMemberNumber = Agent::fromCompany($this->company)
                                ->fromApp($this->app)
                                ->lockForUpdate()
                                ->max('member_id');

        return $currentMaxMemberNumber + 1;
    }
}
