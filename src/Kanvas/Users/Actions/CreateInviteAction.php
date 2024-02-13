<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\AppSettingsEnums;
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
     */
    public function execute(): UsersInvite
    {
        $companyBranch = CompaniesBranches::getById($this->inviteDto->companies_branches_id);
        $company = $companyBranch->company()->get()->first();

        CompaniesRepository::userAssociatedToCompanyAndBranch(
            $company,
            $companyBranch,
            $this->user
        );

        //validate role
        RolesRepository::getByIdFromCompany(
            $this->inviteDto->role_id,
            $company
        );

        $invite = new UsersInvite();
        $invite->fill([
            'invite_hash' => Str::random(50),
            'users_id' => $this->user->getId(),
            'companies_id' => $company->getKey(),
            'companies_branches_id' => $companyBranch->getKey(),
            'role_id' => $this->inviteDto->role_id,
            'apps_id' => $this->inviteDto->app->getId(),
            'email' => $this->inviteDto->email,
            'firstname' => $this->inviteDto->firstname,
            'lastname' => $this->inviteDto->lastname,
            'description' => $this->inviteDto->description,
        ]);

        $invite->saveOrFail();

        /*
        $userTemp = new Users();
        $userTemp->fill([
             'email' => $this->inviteDto->email,
             'firstname' => $this->inviteDto->firstname,
             'lastname' => $this->inviteDto->lastname,
             'default_company' => $company->getId(),
             'default_company_branch' => $companyBranch->getId(),
         ]);
 */
        //@todo allow it to be customized
        $emailTitle = $this->inviteDto->app->get(AppSettingsEnums::INVITE_EMAIL_SUBJECT->getValue()) ?? 'You\'ve been invited to join ' . $company->name;

        $inviteEmail = new InviteTemplate($invite, [
            'fromUser' => $this->user,
            'subject' => $emailTitle,
            'template' => $this->inviteDto->email_template,
            'company' => $company,
        ]);

        Notification::route('mail', $this->inviteDto->email)
            ->notify($inviteEmail);

        return $invite;
    }
}
