<?php

namespace Kanvas\Users\Users\Observers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Users\Models\Users;

class UsersObserver
{
    /**
     * Before create.
     *
     * @param Users $user
     *
     * @return void
     */
    public function created(Users $user) : void
    {
        //for now


        //create company
    }

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function saving(Users $user) : void
    {
        $user->uuid = Str::uuid()->toString();
        $user->system_modules_id = SystemModules::first()->id;
    }
}
