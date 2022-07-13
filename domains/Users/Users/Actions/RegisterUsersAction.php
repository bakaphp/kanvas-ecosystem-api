<?php

declare(strict_types=1);

namespace Kanvas\Users\Users\Actions;

use Kanvas\Users\Users\Models\Users;
use Kanvas\Users\Users\DataTransferObject\RegisterPostData;

class RegisterUsersAction
{
    /**
     * Construct function
     */
    public function __construct(
        protected RegisterPostData $data
    ) {
    }

    /**
     * Invoke function
     *
     * @param RegisterPostData $data
     *
     * @return Users
     */
    public function execute(): Users
    {
        $user = new Users();
        $user->firstname = $this->data->firstname;
        $user->lastname = $this->data->lastname;
        $user->displayname = $this->data->displayname;
        $user->email = $this->data->email;
        $user->password = $this->data->password;
        $user->default_company = $this->data->default_company;
        $user->save();

        return $user;
    }
}
