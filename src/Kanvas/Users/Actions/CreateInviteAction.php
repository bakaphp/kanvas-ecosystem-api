<?php
declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Notifications\Templates\Invite as InviteTemplate;
use Kanvas\Users\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;

class CreateInviteAction
{
    public function __construct(
        public InviteDto $inviteDto,
        public Users $user
    ) {
    }

    /**
     * execute.
     *
     * @return bool
     */
    public function execute() : UsersInvite
    {
        $companyBranch = CompaniesBranches::getById($this->inviteDto->companies_branches_id);
        $company = $companyBranch->company()->get()->first();

        CompaniesRepository::userAssociatedToCompanyAndBranch(
            $company,
            $companyBranch,
            $this->user
        );

        $invite = new UsersInvite();
        $invite->fill([
            'invite_hash' => Str::random(50),
            'users_id' => $this->user ? $this->user->id : auth()->user()->id,
            'companies_id' => $company->getKey(),
            'companies_branches_id' => $companyBranch->getKey(),
            'role_id' => $this->inviteDto->role_id,
            'apps_id' => app(Apps::class)->getKey(),
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

        return $invite;
    }
}
