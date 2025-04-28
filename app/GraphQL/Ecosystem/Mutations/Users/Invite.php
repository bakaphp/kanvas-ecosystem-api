<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Actions\CreateInviteAction;
use Kanvas\Users\Actions\ProcessInviteAction;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;
use Kanvas\Users\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Users\Repositories\UsersInviteRepository;

class Invite
{
    /**
     * insertInvite.
     *
     */
    public function insertInvite($rootValue, array $request): UsersInvite
    {
        $request = $request['input'];
        $app = app(Apps::class);
        $invite = new CreateInviteAction(
            new InviteDto(
                $app,
                $request['companies_branches_id'],
                $request['role_id'],
                $request['email'],
                $request['firstname'] ?? null,
                $request['lastname'] ?? null,
                $request['description'] ?? null,
            ),
            auth()->user()
        );
        return $invite->execute();
    }

    /**
     * deleteInvite.
     *
     */
    public function deleteInvite($rootValue, array $request): bool
    {
        $invite = UsersInviteRepository::getById(
            $request['id'],
            auth()->user()->getCurrentCompany()
        );

        $invite->softDelete();
        return true;
    }

    /**
     * processInvite.
     *
     */
    public function get($rootValue, array $request): UsersInvite
    {
        //$action = new ProcessInviteAction($request['hash'], $request['password']);
        return UsersInviteRepository::getByHash($request['hash']);
    }

    /**
     * Process User invite.
     *
     */
    public function process($rootValue, array $request): Users
    {
        $action = new ProcessInviteAction(
            CompleteInviteInput::from($request['input'])
        );
        return $action->execute();
    }
}
