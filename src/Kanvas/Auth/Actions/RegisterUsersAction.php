<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Users\Services\UserNotificationService;
use Kanvas\Workflow\Enums\WorkflowEnum;

class RegisterUsersAction extends CreateUserAction
{
    /**
     * Invoke function.
     * @todo improve duplicate code
     *
     * @param RegisterInput $data
     */
    public function execute(): Users
    {
        $newUser = false;
        $company = $this->data->branch ? $this->data->branch->company : null;

        $this->validateEmail();

        try {
            /**
             * If the user exist we have to verify if it exist in this app
             * if it does , means the user already exist
             * if it doesn't than we have to create the user in this app and with a new company
             */
            $user = Users::getByEmail($this->data->email);

            try {
                UsersRepository::belongsToThisApp($user, $this->app);

                throw new AuthenticationException('Email has already been taken.');
            } catch (ModelNotFoundException $e) {
                $this->registerUserInApp($user);
                $company = $this->createCompany($user);
            }
        } catch (ModelNotFoundException $e) {
            $newUser = true;
            $user = $this->createNewUser();

            // if company is not set we create a new company
            if (! $company) {
                $company = $this->createCompany($user);
            } else {
                $this->assignCompany($user);
            }

            $this->registerUserInApp($user);
            $this->assignUserRole($user);
        }

        UserNotificationService::sendWelcomeEmail($this->app, $user, $company);

        if ($newUser) {
            $this->onBoarding($user, $company);
        }
        if ($this->runWorkflow) {
            $user->fireWorkflow(
                WorkflowEnum::REGISTERED->value,
                true,
                [
                    'company' => $company,
                    'password' => $this->data->raw_password,
                ]
            );
        }

        return $user;
    }
}
