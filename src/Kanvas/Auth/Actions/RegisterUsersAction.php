<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Templates\Welcome;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Jobs\OnBoardingJob;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class RegisterUsersAction
{
    protected Apps $app;

    /**
     * Construct function.
     */
    public function __construct(
        protected RegisterInput $data
    ) {
        $this->app = app(Apps::class);
    }

    /**
     * Invoke function.
     *
     * @param RegisterInput $data
     */
    public function execute(): Users
    {
        $validator = Validator::make(
            ['email' => $this->data->email],
            ['email' => 'required|email']
        );

        // This is the second time that we need get user data without an exception.
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

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
                $userRegisterInApp = new RegisterUsersAppAction($user);
                $userRegisterInApp->execute($this->data->password);

                //create new company for user on this app
                $createCompany = new CreateCompaniesAction(
                    new CompaniesPostData(
                        $user->defaultCompanyName ?? $user->displayname . 'CP',
                        $user->id,
                        $user->email
                    )
                );

                $company = $createCompany->execute();
            }
        } catch(ModelNotFoundException $e) {
            $user = new Users();
            $user->firstname = $this->data->firstname;
            $user->lastname = $this->data->lastname;
            $user->displayname = $this->data->displayname;
            $user->email = $this->data->email;
            $user->password = $this->data->password;
            $user->sex = AppEnums::DEFAULT_SEX->getValue();
            $user->dob = date('Y-m-d');
            $user->lastvisit = date('Y-m-d H:i:s');
            $user->registered = date('Y-m-d H:i:s');
            $user->timezone = AppEnums::DEFAULT_TIMEZONE->getValue();
            $user->user_active = StatusEnums::ACTIVE->getValue();
            $user->status = StatusEnums::ACTIVE->getValue();
            $user->banned = StateEnums::NO->getValue();
            $user->user_login_tries = 0;
            $user->user_last_login_try = 0;
            $user->default_company = $this->data->default_company ?? StateEnums::NO->getValue();
            $user->session_time = time();
            $user->session_page = StateEnums::NO->getValue();
            $user->password = $this->data->password;
            $user->language = $user->language ?: AppEnums::DEFAULT_LANGUAGE->getValue();
            $user->user_activation_key = Hash::make(time());
            $user->roles_id = $this->data->roles_id ?? AppEnums::DEFAULT_ROLE_ID->getValue(); //@todo : remove this , legacy code

            //create a new user assign it to the app and create the default company
            $user->saveOrFail();

            $userRegisterInApp = new RegisterUsersAppAction($user);
            $userRegisterInApp->execute($this->data->password);

            $userRole = RolesRepository::getByMixedParamFromCompany($this->data->roles_id ?? DefaultRoles::ADMIN->getValue());

            $assignRole = new AssignRoleAction(
                $user,
                $userRole
            );
            $assignRole->execute();
        }

        try {
            if ($this->app->get((string) AppSettingsEnums::SEND_WELCOME_EMAIL->getValue())) {
                $user->notify(new Welcome($user));
            }

            //create CRM + Inventory for user company send it to job
            OnBoardingJob::dispatch(
                $user,
                $company instanceof CompanyInterface ? $company->defaultBranch()->firstOrFail() : $user->getCurrentBranch(),
                $this->app
            );
        } catch (Throwable $e) {
            //no email sent
        }

        return $user;
    }
}
