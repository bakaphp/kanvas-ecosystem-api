<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Notifications\Templates\Welcome;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Models\Users;

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
     *
     * @return Users
     */
    public function execute(): Users
    {
        $user = Users::where(
            [
                'email' => $this->data->email,
                'is_deleted' => 0,
            ]
        )->first();

        /**
         * @todo ecosystemAuth
         */
        if ($user) {
            throw new AuthenticationException('Email already exists');
        }

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
        $user->saveOrFail();

        try {
            $user->notify(new Welcome($user));
        } catch (ModelNotFoundException $e) {
            //no email sent
        }

        return $user;
    }
}
