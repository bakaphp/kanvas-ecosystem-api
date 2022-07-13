<?php

namespace Kanvas\Users\Users\Observers;

use Illuminate\Support\Str;
use Kanvas\Roles\Models\Roles;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Users\Models\Users;

class UsersObserver
{
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
        $user->user_active = 1;
        $user->roles_id = Roles::first()->id;
        $user->system_modules_id = SystemModules::first()->id;
    }
}
