<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Random;
use Baka\Support\Str;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Exceptions\ModelNotFoundException;
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
        $zohoService = new ZohoService($this->app, $this->company);

        $record = $zohoService->getAgentByEmail($this->email);

        $name = explode(' ', $record->Name);
        $firstName = $name[0];
        $lastName = $name[1] ?? '';
        $memberNumber = $record->Member_Number;
        $zohoId = $record->id;
        $owner = $record->Owner;

        $ownerUser = Users::getByEmail($owner['email']);
        $ownerAgent = Agent::where('users_id', $ownerUser->getId())->fromCompany($this->company)->first();

        try {
            $user = Users::getByEmail($this->email);
            $user->getAppProfile($this->app);

            $user->firstname = $firstName;
            $user->lastname = $lastName;
            $user->saveOrFail();
        } catch (ModelNotFoundException $e) {
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

        return Agent::updateOrCreate(
            [
                'users_id' => $user->getId(),
                'companies_id' => $this->company->getId(),
                'member_id' => $memberNumber,
            ],
            [
                'name' => $record->Name,
                'owner_linked_source_id' => $owner['id'],
                'users_linked_source_id' => $zohoId,
                'owner_id' => $ownerAgent ? $ownerAgent->member_id : null,
                'status_id' => 1, // Active
                'updated_at' => date('Y-m-d H:i:s'),
                //'apps_id' => $this->app->getId(),
            ]
        );
    }
}
