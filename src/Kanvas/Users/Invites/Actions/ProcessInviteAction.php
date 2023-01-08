<?php
declare(strict_types=1);
namespace Kanvas\Users\Invites\Actions;

use Kanvas\Users\Invites\Models\UsersInvite as UsersInviteModel;
use Kanvas\Users\Invites\Repository\UsersInviteRepository;
use Auth;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Users\Models\Users;

class ProcessInviteAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public string $hash,
        public string $password,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute(): Users
    {
        $invite = UsersInviteRepository::getByHash($this->hash);
        Auth::setUser($invite->user);
        $dto = RegisterPostDataDto::fromArray([
            'email' => $invite->email,
            'password' => $this->password,
            'firstname' => $invite->firstname,
            'lastname' => $invite->lastname,
            'default_company' => (string)$invite->companies_id,
            'roles_id' => $invite->role_id
        ]);
        $user = (new RegisterUsersAction($dto))->execute();
        $invite->delete();
        return $user;
    }
}
