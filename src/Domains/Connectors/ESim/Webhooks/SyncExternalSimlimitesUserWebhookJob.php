<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Esim\Webhooks;

use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Exceptions\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Override;
use Throwable;

class SyncExternalSimlimitesUserWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        try {
            $userData = $this->webhookRequest->payload;

            if (empty($userData['email']) || empty($userData['firstname']) || empty($userData['lastname'])) {
                return [
                    'message' => 'Missing required user data',
                    'status' => 'error'
                ];
            }

            try {
                $user = Users::getByEmail($userData['email']);
                return $this->handleExistingUser($user, $userData);
            } catch (ModelNotFoundException) {
                $user = $this->createNewUser($userData);

                return [
                    'message' => 'New user created successfully',
                    'user_id' => $user->getId(),
                    'status' => 'success'
                ];
            }
        } catch (Throwable $e) {
            return [
                'message' => 'Error processing user creation: ' . $e->getMessage(),
                'status' => 'error'
            ];
        }
    }

    private function handleExistingUser(Users $user, array $userData): array
    {
        try {
            UsersRepository::belongsToThisApp($user, $this->receiver->app);

            $this->updateExistingUser($user, $userData);

            return [
                'message' => 'User already exists in this app, data updated',
                'user_id' => $user->getId(),
                'status' => 'success'
            ];
        } catch (ModelNotFoundException) {
            // Usuario existe pero no en esta app, lo registramos
            $this->registerExistingUserInApp($user, $userData);
            
            return [
                'message' => 'User exists but was added to this app',
                'user_id' => $user->getId(),
                'status' => 'success'
            ];
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
            'password' => $userData['password'] ?? null,
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

        $user->firstname = $userData['firstname'] ?? $user->firstname;
        $user->lastname = $userData['lastname'] ?? $user->lastname;
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
        $password = $userData['password'] ?? null;
        $rawPassword = $userData['password'] ?? null;

        return new RegisterInput(
            email: $userData['email'],
            firstname: $userData['firstname'],
            lastname: $userData['lastname'],
            displayname: $userData['displayname'] ?? $userData['firstname'] . ' ' . $userData['lastname'],
            password: $password,
            raw_password: $rawPassword,
            phone_number: $userData['phone_number'] ?? null,
            cell_phone_number: $userData['cell_phone_number'] ?? null,
            custom_fields: $userData['custom_fields'] ?? [],
            branch: $this->receiver->company->defaultBranch,
            role_ids: $userData['role_ids'] ?? null,
        );
    }
}