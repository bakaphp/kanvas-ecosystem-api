<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Auth;

use Kanvas\Users\Invites\Models\UsersInvite;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Notifications\Templates\Invite as InviteTemplate;
use Kanvas\Users\Invites\Actions\CreateInvite as CreateInviteAction;
use Kanvas\Users\Invites\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Invites\Actions\DeleteInvite as DeleteInviteAction;
use Kanvas\Users\Invites\Actions\ProcessInvite as ProcessInviteAction;

class Invite
{
    /**
     * insertInvite
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return bool
     */
    public function insertInvite($rootValue, array $request): bool
    {
        $request = $request['input'];
        $invite = new CreateInviteAction(
            new InviteDto(
                $request['companies_branches_id'],
                $request['role_id'],
                $request['email'],
                $request['firstname'] ?? null,
                $request['lastname'] ?? null,
                $request['description'] ?? null,
            )
        );
        $invite->execute();
        return true;
    }

    /**
     * deleteInvite
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return bool
     */
    public function deleteInvite($rootValue, array $request): bool
    {
        $action = new DeleteInviteAction($request['id']);
        $action->execute();
        return true;
    }

    /**
     * processInvite
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return bool
     */
    public function processInvite($rootValue, array $request): Users
    {
        $action = new ProcessInviteAction($request['hash'], $request['password']);
        return  $action->execute();
    }
}
