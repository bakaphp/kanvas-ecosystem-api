<?php

declare(strict_types=1);

namespace Kanvas\UsersGroup\Users\Actions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Apps\Enums\Defaults;
use Kanvas\Enums\StateEnums;
use Kanvas\UsersGroup\Enums\StatusEnums;
use Kanvas\UsersGroup\Users\DataTransferObject\RegisterPostData;
use Kanvas\UsersGroup\Users\Models\Users;

class RegisterUsersAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected RegisterPostData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @param RegisterPostData $data
     *
     * @return Users
     */
    public function execute() : Users
    {
        $user = Users::where(
            [
                'email' => $this->data->email,
                'is_deleted' => 0
            ]
        )->first();

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
        $user->sex = Defaults::DEFAULT_SEX->getValue();
        $user->dob = date('Y-m-d');
        $user->lastvisit = date('Y-m-d H:i:s');
        $user->registered = date('Y-m-d H:i:s');
        $user->timezone = Defaults::DEFAULT_TIMEZONE->getValue();
        $user->user_active = StatusEnums::ACTIVE->getValue();
        $user->status = StatusEnums::ACTIVE->getValue();
        $user->banned = StateEnums::NO->getValue();
        $user->user_login_tries = 0;
        $user->user_last_login_try = 0;
        $user->default_company = $user->default_company ?? StateEnums::NO->getValue();
        $user->session_time = time();
        $user->session_page = StateEnums::NO->getValue();
        $user->password = $this->data->password;
        $user->language = $user->language ?: Defaults::DEFAULT_LANGUAGE->getValue();
        $user->user_activation_key = Hash::make(time());
        $user->saveOrFail();

        return $user;
    }
}
