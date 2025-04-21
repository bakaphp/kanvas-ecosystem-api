<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Webhooks;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Override;

class SyncExternalWooCommerceUserWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
            $userData = $this->webhookRequest->payload;
            if (empty($userData['email']) || empty($userData['firstname']) || empty($userData['lastname'])) {
                return [
                    'message' => 'Missing required user data',
                    'status' => 'error',
                ];
            }

        $userExists = $this->checkUserExists($userData['email']);

        if ($userExists) {
            return $this->handleExistingUser($userData);
        } else {
            $user = $this->createNewUser($userData);

            return [
                'message' => 'New user created successfully',
                'user_id' => $user->getId(),
                'status' => 'success',
            ];
        }
    }

    private function checkUserExists(string $email): bool
    {
        try {
            $user = Users::getByEmail($email);

            try {
                UsersRepository::belongsToThisApp($user, $this->receiver->app);

                return true;
            } catch (ModelNotFoundException) {
                return true;
            }
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    private function handleExistingUser(array $userData): array
    {
        try {
            $user = Users::getByEmail($userData['email']);

            try {
                UsersRepository::belongsToThisApp($user, $this->receiver->app);
                $this->updateExistingUser($user, $userData);

                return [
                    'message' => 'User already exists in this app, data updated',
                    'user_id' => $user->getId(),
                    'status' => 'success',
                ];
            } catch (ModelNotFoundException) {
                $this->registerExistingUserInApp($user, $userData);

                return [
                    'message' => 'User exists but was added to this app',
                    'user_id' => $user->getId(),
                    'status' => 'success',
                ];
            }
        } catch (ModelNotFoundException $e) {
            throw $e;
        }
    }

    private function createNewUser(array $userData): Users
    {
        $registerInput = $this->prepareRegisterInput($userData);

        $createUserAction = new CreateUserAction($registerInput, $this->receiver->app);

        if (isset($userData['run_workflow']) && $userData['run_workflow'] === false) {
            $createUserAction->disableWorkflow();
        }

        return $createUserAction->execute();
    }

    private function registerExistingUserInApp(Users $user, array $userData): void
    {
        $registerInput = $this->prepareRegisterInput([
            'email' => $user->email,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'displayname' => $user->displayname,
            'password' => $userData['password'],
            'phone_number' => $user->phone_number,
            'cell_phone_number' => $user->cell_phone_number,
            'custom_fields' => $userData['custom_fields'] ?? [],
        ]);

        $createUserAction = new CreateUserAction($registerInput, $this->receiver->app);
        if (isset($userData['run_workflow']) && $userData['run_workflow'] === false) {
            $createUserAction->disableWorkflow();
        }

        $createUserAction->execute();
    }

    private function updateExistingUser(Users $user, array $userData): void
    {
        $user->firstname = $userData['first_name'] ?? $user->firstname;
        $user->lastname = $userData['last_name'] ?? $user->lastname;
        $user->displayname = $userData['displayname'] ?? $user->firstname . ' ' . $user->lastname;
        $user->phone_number = $userData['phone_number'] ?? $user->phone_number;
        $user->cell_phone_number = $userData['cell_phone_number'] ?? $user->cell_phone_number;

        if (! empty($userData['custom_fields'])) {
            $user->setAll($userData['custom_fields']);
        }

        $user->saveOrFail();
    }

    private function prepareRegisterInput(array $userData): RegisterInput
    {
        $password = $userData['password'] ?? Hash::make(Str::random(10));
        $rawPassword = $userData['password'] ?? null;
        $userRoleId = $userData['role_ids'] ?? RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $this->receiver->company, $this->receiver->app)->id;

        return new RegisterInput(
            email: $userData['email'],
            firstname: $userData['first_name'],
            lastname: $userData['last_name'],
            displayname: $userData['displayname'] ?? $userData['first_name'] . ' ' . $userData['last_name'],
            password: $password,
            raw_password: $rawPassword,
            phone_number: $userData['phone_number'] ?? null,
            cell_phone_number: $userData['cell_phone_number'] ?? null,
            custom_fields: $userData['custom_fields'] ?? [],
            branch: $this->receiver->company->defaultBranch,
            role_ids: [$userRoleId],
        );
    }
}
