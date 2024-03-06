<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class RegisterUsersAppAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users|UserInterface $user,
        protected ?Apps $app = null
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * Register an user into a new app with a password for the login.
     *
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(string $password): UsersAssociatedApps
    {
        /**
         * for now use use company 0 has a default , for all user info on this app
         * in future version we will remove company id from this table
         */
        return UsersAssociatedApps::firstOrCreate([
            'users_id' => $this->user->getKey(),
            'apps_id' => $this->app->getId(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
        ], [
            'identify_id' => $this->user->getKey(),
            'password' => $password,
            'firstname' => $this->user->firstname,
            'lastname' => $this->user->lastname,
            'email' => $this->user->email,
            'is_active' => StatusEnums::ACTIVE->getValue(),
            'user_active' => StatusEnums::ACTIVE->getValue(),
            'user_role' => $this->user->roles_id ?? AppEnums::DEFAULT_ROLE_ID->getValue(),
            'displayname' => $this->user->displayname,
            'lastvisit' => date('Y-m-d H:i:s'),
            'session_time' => time(),
            'welcome' => 0,
            'user_login_tries' => 0,
            'user_last_login_try' => 0,
            'user_activation_key' => Hash::make(time()),
            'banned' => StateEnums::NO->getValue(),
            'status' => StatusEnums::ACTIVE->getValue(),
        ]);
    }
}
