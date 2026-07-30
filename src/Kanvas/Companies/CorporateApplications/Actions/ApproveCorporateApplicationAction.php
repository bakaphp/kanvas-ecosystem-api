<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Turns an approved corporate application into a usable account. Two shapes, picked by
 * whether the application names an existing user:
 *
 *  - a fresh applicant gets a corporate Company plus an admin invite;
 *  - an existing user upgrading gets `is_corporate` flipped on the Company they already
 *    provisioned, with no invite because the account is already there.
 *
 * `is_corporate` is deliberately the last thing set: it is the switch that grants corporate
 * privilege, so nothing before approval should turn it on.
 *
 * Idempotent — re-running reuses the Company and UsersInvite recorded on the application,
 * which is what makes workflow retries and double-clicks in an admin panel safe.
 *
 * Product-specific side effects hang off the `corporate-application-approved` workflow event
 * rather than living here, so this stays free of any connector knowledge.
 */
class ApproveCorporateApplicationAction
{
    public function __construct(
        protected readonly Model $application,
        protected readonly Apps $app,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        try {
            $result = Field::UPGRADE_USER_ID->readFrom($this->application)
                ? $this->grantUpgrade()
                : $this->provisionNewAccount();

            $this->application->fireWorkflow(
                WorkflowEnum::CORPORATE_APPLICATION_APPROVED->value,
                params: ['app' => $this->app],
            );

            return $result;
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }

    private function provisionNewAccount(): array
    {
        $owner = $this->applicationOwner();
        $company = $this->findOrCreateCompany($owner);

        $this->copyCompanyFields($company);

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

    /**
     * The account the Company is created under. A webhook application has no user of its own,
     * so it belongs to whoever owns the intake — the receiver's user.
     */
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

    private function copyCompanyFields(Companies $company): void
    {
        $company->set('is_corporate', true);

        foreach (Field::COMPANY_FIELDS as $key) {
            $value = $this->application->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            $company->set($key, $value);
        }
    }

    private function findOrCreateInvite(Companies $company, Users $owner): UsersInvite
    {
        $existingHash = Field::INVITE_HASH->readFrom($this->application);

        if ($existingHash) {
            $existing = UsersInvite::where('invite_hash', $existingHash)->first();

            if ($existing) {
                return $existing;
            }
        }

        $branch = $company->branch()->firstOrFail();
        $adminRole = RolesRepository::getByMixedParamFromCompany(RolesEnums::ADMIN->value, $company, $this->app);

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

        // The is_corporate marker on the invite is what the propagation step looks for when
        // the invite is accepted.
        $invite->set('is_corporate', true);

        foreach (Field::USER_FIELDS as $key) {
            if ($key === 'is_corporate') {
                continue;
            }

            $value = $this->application->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            $invite->set($key, $value);
        }

        return $invite;
    }

    private function sendWelcomeEmail(Companies $company, UsersInvite $invite): void
    {
        $email = trim((string) $this->application->email);

        if ($email === '') {
            return;
        }

        $templateName = (string) (Setting::WELCOME_TEMPLATE->readFrom($this->app) ?: 'corporate-welcome');
        $inviteBaseUrl = rtrim((string) (Setting::INVITE_LINK_BASE->readFrom($this->app) ?? ''), '/');

        $notification = new Blank($templateName, [
            'app' => $this->app,
            'lead' => $this->application,
            'company' => $company,
            'invite' => $invite,
            'inviteHash' => $invite->invite_hash,
            'inviteUrl' => $inviteBaseUrl !== '' ? $inviteBaseUrl . '/' . $invite->invite_hash : null,
            'corporateLegalName' => $this->application->get('legal_name'),
            'contactName' => $this->application->get('contact_name') ?? $this->application->firstname,
        ], ['mail'], $this->application);
        $notification->setSubject('Bienvenido al portal corporativo');

        try {
            LaravelNotification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            // Email failures don't fail approval — Company/Invite are the source of truth.
            report($e);
        }
    }
}
