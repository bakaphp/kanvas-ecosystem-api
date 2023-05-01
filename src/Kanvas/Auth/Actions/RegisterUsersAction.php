<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Templates\Welcome;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;

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
            $user = Users::getByEmail($this->data->email);

            try {
                UsersRepository::belongsToThisApp($user, $this->app);

                throw new AuthenticationException('Email has already been taken.');
            } catch (ModelNotFoundException $e) {
                UsersAssociatedApps::registerUserApp($user, $this->data->password);

                //create new company for user on this app
                $createCompany = new CreateCompaniesAction(
                    new CompaniesPostData(
                        $user->defaultCompanyName ?? $user->displayname . 'CP',
                        $user->id,
                        $user->email
                    )
                );
    
                $createCompany->execute();
            }
        } catch(ModelNotFoundException $e) {
            $user = new Users();
            $user->firstname = $this->data->firstname;
            $user->lastname = $this->data->lastname;
            $user->displayname = $this->data->displayname;
            $user->email = $this->data->email;
            $user->password = $this->data->password;
            $user->default_company = $this->data->default_company;
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
            $user->default_company = $user->default_company ?? StateEnums::NO->getValue();
            $user->session_time = time();
            $user->session_page = StateEnums::NO->getValue();
            $user->password = $this->data->password;
            $user->language = $user->language ?: AppEnums::DEFAULT_LANGUAGE->getValue();
            $user->user_activation_key = Hash::make(time());
            $user->roles_id = $this->data->roles_id ?? AppEnums::DEFAULT_ROLE_ID->getValue();

            //create a new user assign it to the app and create the default company
            $user->saveOrFail();

            UsersAssociatedApps::registerUserApp($user, $this->data->password);
        }

        try {
            $user->notify(new Welcome($user));
        } catch (ModelNotFoundException $e) {
            //no email sent
        }

        return $user;
    }
}
