<?php
declare(strict_types=1);
namespace Kanvas\Users\Invites\Actions;

use Kanvas\Users\Invites\DataTransferObject\Invite as InviteDto;
use Kanvas\Notifications\Templates\Invite as InviteTemplate;
use Kanvas\Users\Invites\Models\UsersInvite;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Str;
use Kanvas\Users\Models\Users;

class CreateInvite
{
    public function __construct(
        public InviteDto $inviteDto,
    ) {
    }

    /**
     * execute
     *
     * @return bool
     */
    public function execute(): bool
    {
        $invite = new UsersInvite();
        $invite->fill([
            'invite_hash' => Str::random(30),
            'users_id' => auth()->user()->id,
            'companies_id' => auth()->user()->defaultCompany->id,
            'companies_branches_id' => $this->inviteDto->companies_branches_id,
            'role_id' => $this->inviteDto->role_id,
            'apps_id' => app(Apps::class)->id,
            'email' => $this->inviteDto->email,
            'firstname' => $this->inviteDto->firstname,
            'lastname' => $this->inviteDto->lastname,
            'description' => $this->inviteDto->description,
        ]);
        $invite->saveOrFail();
        $userTemp = new Users();
        $userTemp->fill([
            'email' => $this->inviteDto->email,
            'firstname' => $this->inviteDto->firstname,
            'lastname' => $this->inviteDto->lastname,
            'companies_id' => auth()->user()->defaultCompany->id,
        ]);
        $userTemp->notify(new InviteTemplate($invite));

        return true;
    }
}
