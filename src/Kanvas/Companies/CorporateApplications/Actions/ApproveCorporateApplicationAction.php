<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\CorporateApplications\Concerns\SendsApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Enums\WorkflowEnum;

class ApproveCorporateApplicationAction
{
    use SendsApplicationEmail;

    public function __construct(
        protected readonly Model $application,
        protected readonly Apps $app,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        $missing = Field::missing(Field::requiredFor($this->application->receiver), $this->application->get(...));

        if ($missing !== []) {
            throw new ValidationException('Cannot approve: missing ' . implode(', ', $missing));
        }

        $result = Field::UPGRADE_USER_ID->readFrom($this->application)
            ? $this->grantUpgrade()
            : $this->provisionNewAccount();

        $this->application->fireWorkflow(
            WorkflowEnum::CORPORATE_APPLICATION_APPROVED->value,
            params: ['app' => $this->app],
        );

        return $result;
    }

    private function provisionNewAccount(): array
    {
        $owner = $this->applicationOwner();
        $company = $this->findOrCreateCompany($owner);

        $this->copyCompanyFields($company);
        $this->provisionCompanyDefaults($owner, $company);

        $invite = $this->findOrCreateInvite($company, $owner);

        Field::COMPANY_ID->writeTo($this->application, (string) $company->getId());
        Field::INVITE_HASH->writeTo($this->application, $invite->invite_hash);
        $this->markApproved();

        $this->sendWelcomeEmail($company, $invite);

        return [
            'application' => $this->application->getId(),
            'status' => CorporateApplicationStatusEnum::APPROVED->value,
            'company_id' => $company->getId(),
            'invite_hash' => $invite->invite_hash,
        ];
    }

    private function grantUpgrade(): array
    {
        $company = Companies::getById((int) Field::COMPANY_ID->readFrom($this->application));
        $user = Users::getById((int) Field::UPGRADE_USER_ID->readFrom($this->application));

        $company->set('is_corporate', true);
        $user->set('is_corporate', true);

        new SwitchCompanyBranchAction($user, $company->branch()->firstOrFail()->getId())->execute();

        $this->markApproved();

        return [
            'application' => $this->application->getId(),
            'status' => CorporateApplicationStatusEnum::APPROVED->value,
            'company_id' => $company->getId(),
            'invite_hash' => null,
        ];
    }

    private function markApproved(): void
    {
        Field::STATUS->writeTo($this->application, CorporateApplicationStatusEnum::APPROVED->value);

        if (! $this->reviewedBy) {
            return;
        }

        Field::REVIEWED_BY->writeTo($this->application, (string) $this->reviewedBy->getId());
        Field::REVIEWED_AT->writeTo($this->application, now()->toIso8601String());
    }

    private function applicationOwner(): Users
    {
        return $this->application->receiver->user;
    }

    private function findOrCreateCompany(Users $owner): Companies
    {
        $existingCompanyId = Field::COMPANY_ID->readFrom($this->application);

        if ($existingCompanyId) {
            return Companies::getById((int) $existingCompanyId);
        }

        $name = trim((string) ($this->application->get('commercial_name')
            ?: $this->application->get('legal_name')
            ?: $this->application->title
            ?: 'Corporate Account'));

        return new CreateCompaniesAction(
            new CompanyData(
                user: $owner,
                name: $name,
                email: trim((string) ($this->application->get('contact_email') ?: $this->application->email)),
                phone: trim((string) ($this->application->get('contact_phone') ?: $this->application->phone ?? '')),
            ),
        )->execute();
    }

    private function provisionCompanyDefaults(Users $owner, Companies $company): void
    {
        new SetupService()->onBoarding($owner, $this->app, $company);
    }

    private function copyCompanyFields(Companies $company): void
    {
        $company->set('is_corporate', true);
        Field::copy(Field::companyFieldsFor($this->application->receiver), $this->application->get(...), $company);
    }

    private function findOrCreateInvite(Companies $company, Users $owner): UsersInvite
    {
        $existingHash = Field::INVITE_HASH->readFrom($this->application);

        if ($existingHash) {
            $existing = UsersInvite::where('invite_hash', $existingHash)->fromApp($this->app)->notDeleted()->first();

            if ($existing) {
                return $existing;
            }
        }

        $branch = $company->branch()->firstOrFail();
        $adminRole = RolesRepository::getByNameFromApp(RolesEnums::ADMIN->value, $this->app);

        $invite = new UsersInvite();
        $invite->fill([
            'invite_hash' => Str::random(50),
            'users_id' => $owner->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $branch->getId(),
            'role_id' => $adminRole->id,
            'apps_id' => $this->app->getId(),
            'email' => trim((string) ($this->application->get('contact_email') ?: $this->application->email)),
            'firstname' => trim((string) ($this->application->get('contact_name') ?: $this->application->firstname ?: '')),
            'lastname' => trim((string) ($this->application->lastname ?: '')),
            'description' => 'Corporate self-signup',
        ]);
        $invite->saveOrFail();

        $invite->set('is_corporate', true);
        Field::copy(Field::userFieldsFor($this->application->receiver), $this->application->get(...), $invite);

        return $invite;
    }

    private function sendWelcomeEmail(Companies $company, UsersInvite $invite): void
    {
        $inviteBaseUrl = rtrim((string) (Setting::INVITE_LINK_BASE->readFrom($this->app) ?? ''), '/');

        $this->sendApplicationEmail(
            $this->app,
            (string) (Setting::WELCOME_TEMPLATE->readFrom($this->app) ?: 'corporate-welcome'),
            'Bienvenido al portal corporativo',
            [
                'lead' => $this->application,
                'company' => $company,
                'invite' => $invite,
                'inviteHash' => $invite->invite_hash,
                'inviteUrl' => $inviteBaseUrl !== '' ? $inviteBaseUrl . '/' . $invite->invite_hash : null,
                'corporateLegalName' => $this->application->get('legal_name'),
                'contactName' => $this->application->get('contact_name') ?? $this->application->firstname,
            ],
            (string) $this->application->email,
            $this->application,
        );
    }
}
