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

            // Get or create owner and their agent record
            $ownerData = $this->getOrCreateOwner($owner);
            $ownerUser = $ownerData['user'];
            $ownerAgent = $ownerData['agent'];

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
                        branch: $this->company->defaultBranch //assign user to default branch
                    ),
                    $this->app
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
            ])->lockForUpdate()->first();

            if ($agent && $agent->member_id != $memberNumber && ! $newMemberNumber) {
                $updatedMemberNumber = true;
            }

            // Update the agent if it exists, otherwise create a new record
            $agentData = [
                'name' => $record->Name,
                'owner_linked_source_id' => $owner['id'],
                'owner_id' => $record->Sponsor ?? ($ownerAgent ? $ownerAgent->member_id : null),
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

    protected function getOrCreateOwner(array $owner): array
    {
        $zohoService = new ZohoService($this->app, $this->company);
        $record = $zohoService->getAgentByEmail($owner['email']);

        // First try to find the existing agent by member number
        $existingAgent = Agent::where([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'member_id' => $record->Member_Number,
        ])->lockForUpdate()->first();

        if ($existingAgent) {
            // If agent exists but email different, update the user's email
            $ownerUser = Users::find($existingAgent->users_id);
            if ($ownerUser->email !== $owner['email']) {
                $ownerUser->email = $owner['email'];
                $ownerUser->saveOrFail();
            }

            return [
                'user' => $ownerUser,
                'agent' => $existingAgent,
            ];
        }

        // If no agent found with that member number, check if user exists by email
        $ownerUser = Users::where('email', $owner['email'])->first();

        if (! $ownerUser) {
            // Create owner user
            $ownerName = explode(' ', $owner['name']);
            $ownerFirstName = $ownerName[0];
            $ownerLastName = implode(' ', array_slice($ownerName, 1));

            $ownerUser = (new CreateUserAction(
                new RegisterInput(
                    firstname: $ownerFirstName,
                    lastname: $ownerLastName,
                    displayname: Random::generateDisplayNameFromEmail($owner['email']),
                    email: $owner['email'],
                    password: Str::random(11),
                    branch: $this->company->defaultBranch //assign user to default branch
                )
            ))->execute();
        }

        // Create owner agent record with the specified member number
        $ownerAgent = Agent::create([
            'users_linked_source_id' => $owner['id'],
            'apps_id' => $this->app->getId(),
            'users_id' => $ownerUser->getId(),
            'companies_id' => $this->company->getId(),
            'member_id' => $record->Member_Number, // Use the provided member number
            'owner_linked_source_id' => $record->Owner['id'],
            'name' => $owner['name'],
            'owner_id' => $record->Sponsor,
            'status_id' => 1,
            'updated_at' => now(),
        ]);

        return [
            'user' => $ownerUser,
            'agent' => $ownerAgent,
        ];
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
