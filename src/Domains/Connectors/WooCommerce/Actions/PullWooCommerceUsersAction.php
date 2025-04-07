<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Services\ForgotPassword;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Kanvas\Users\Repositories\UsersRepository;

class PullWooCommerceUsersAction
{
    public function __construct(
        protected Apps $app
    ) {
    }

    public function execute()
    {
        $wooCommerce = new WooCommerce($this->app);
        $users = $wooCommerce->getUsers();
        foreach ($users as $user) {
            try {
                $user = UsersRepository::getUserOfAppByEmail($user['email'], $this->app);
            } catch (Exception $e) {
                $dto = RegisterInput::from([
                    'email' => $user['email'],
                    'password' => $user['password_hash'],
                    'firstname' => '',
                    'lastname' => '',
                    'displayname' => $user['display_name'],
                ]);
                $createUser = new CreateUserAction($dto, $this->app);
                $user = $createUser->execute();
                (new ForgotPassword($this->app))->forgot($user->email);
            }
        }
    }
}
