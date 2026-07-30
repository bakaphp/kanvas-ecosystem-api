<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateLeadFieldEnum;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Throwable;

/**
 * Shared by the workflow's auto-approve path and the admin panel mutation so both produce
 * identical state.
 *
 * Idempotent — re-running reuses the Company and UsersInvite recorded on the Lead, which is
 * what makes workflow retries and double-clicks in the panel safe.
 */
class ApproveCorporateLeadAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        if ($this->lead->get(CorporateLeadFieldEnum::UPGRADE_USER_ID->value)) {
            return $this->grantUpgrade();
        }

        try {
            $appOwner = $this->lead->receiver->user;
            $company = $this->findOrCreateCompany($appOwner);

            $this->copyCompanyFieldsFromLead($company);

            $invite = $this->findOrCreateInvite($company, $appOwner);

            $this->lead->set(CorporateLeadFieldEnum::COMPANY_ID->value, (string) $company->getId());
            $this->lead->set(CorporateLeadFieldEnum::INVITE_HASH->value, $invite->invite_hash);
            $this->lead->set(CorporateLeadFieldEnum::STATUS->value, CorporateApplicationStatusEnum::APPROVED->value);

            $this->stampReviewer();

            $this->sendWelcomeEmail($company, $invite);

            return [
                'lead' => $this->lead->getId(),
                'status' => CorporateApplicationStatusEnum::APPROVED->value,
                'company_id' => $company->getId(),
                'invite_hash' => $invite->invite_hash,
            ];
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }

    private function app(): Apps
    {
        return $this->lead->app;
    }

    /**
     * `enableCorporateMode` already provisioned the Company and the user exists, so approval
     * only flips the privilege on — no second Company, no invite to an existing account.
     */
    private function grantUpgrade(): array
    {
        $company = Companies::getById((int) $this->lead->get(CorporateLeadFieldEnum::COMPANY_ID->value));
        $user = Users::getById((int) $this->lead->get(CorporateLeadFieldEnum::UPGRADE_USER_ID->value));

        new GrantCorporateModeAction(
            company: $company,
            user: $user,
            app: $this->app(),
            sourceCompanyId: (int) $this->lead->get(CorporateLeadFieldEnum::UPGRADE_SOURCE_COMPANY_ID->value),
        )->execute();

        $this->lead->set(CorporateLeadFieldEnum::STATUS->value, CorporateApplicationStatusEnum::APPROVED->value);
        $this->stampReviewer();

        return [
            'lead' => $this->lead->getId(),
            'status' => CorporateApplicationStatusEnum::APPROVED->value,
            'company_id' => $company->getId(),
            'invite_hash' => null,
        ];
    }

    private function stampReviewer(): void
    {
        if (! $this->reviewedBy) {
            return;
        }

        $this->lead->set(CorporateLeadFieldEnum::REVIEWED_BY->value, (string) $this->reviewedBy->getId());
        $this->lead->set(CorporateLeadFieldEnum::REVIEWED_AT->value, now()->toIso8601String());
    }

    private function findOrCreateCompany(Users $appOwner): Companies
    {
        $existingCompanyId = $this->lead->get(CorporateLeadFieldEnum::COMPANY_ID->value);

        if ($existingCompanyId) {
            return Companies::getById((int) $existingCompanyId);
        }

        $companyName = trim((string) ($this->lead->get('commercial_name')
            ?: $this->lead->get('legal_name')
            ?: $this->lead->title
            ?: 'Corporate Account'));

        return new CreateCompaniesAction(
            new CompanyData(
                user: $appOwner,
                name: $companyName,
                email: trim((string) ($this->lead->get('contact_email') ?: $this->lead->email)),
                phone: trim((string) ($this->lead->get('contact_phone') ?: $this->lead->phone ?? '')),
            ),
        )->execute();
    }

    private function copyCompanyFieldsFromLead(Companies $company): void
    {
        $company->set('is_corporate', true);

        foreach (CorporateLeadFieldEnum::COMPANY_FIELDS as $key) {
            $value = $this->lead->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            $company->set($key, $value);
        }

        $regionId = $this->lead->get('region_id');
        if (! empty($regionId)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $regionId);
        } elseif (app()->bound(Regions::class)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, app(Regions::class)->getId());
        }
    }

    private function findOrCreateInvite(Companies $company, Users $appOwner): UsersInvite
    {
        $existingHash = $this->lead->get(CorporateLeadFieldEnum::INVITE_HASH->value);

        if ($existingHash) {
            $existing = UsersInvite::where('invite_hash', $existingHash)->first();

            if ($existing) {
                return $existing;
            }
        }

        $app = $this->app();
        $branch = $company->branch()->firstOrFail();
        $adminRole = RolesRepository::getByMixedParamFromCompany(RolesEnums::ADMIN->value, $company, $app);

        $invite = new UsersInvite();
        $invite->fill([
            'invite_hash' => Str::random(50),
            'users_id' => $appOwner->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $branch->getId(),
            'role_id' => $adminRole->id,
            'apps_id' => $app->getId(),
            'email' => trim((string) ($this->lead->get('contact_email') ?: $this->lead->email)),
            'firstname' => trim((string) ($this->lead->get('contact_name') ?: $this->lead->firstname ?: '')),
            'lastname' => trim((string) ($this->lead->lastname ?: '')),
            'description' => 'Corporate self-signup',
        ]);
        $invite->saveOrFail();

        // is_corporate marker is what PropagateCorporateFieldsToUserActivity looks for.
        $invite->set('is_corporate', true);
        foreach (CorporateLeadFieldEnum::USER_FIELDS as $key) {
            if ($key === 'is_corporate') {
                continue;
            }
            $value = $this->lead->get($key);
            if ($value === null || $value === '') {
                continue;
            }
            $invite->set($key, $value);
        }

        return $invite;
    }

    private function sendWelcomeEmail(Companies $company, UsersInvite $invite): void
    {
        $app = $this->app();
        $email = trim((string) $this->lead->email);

        if ($email === '') {
            return;
        }

        $templateName = $app->get(ConfigurationEnum::CORPORATE_WELCOME_TEMPLATE->value)
            ?: 'corporate-welcome';

        $inviteBaseUrl = rtrim((string) ($app->get(ConfigurationEnum::CORPORATE_INVITE_LINK_BASE->value) ?? ''), '/');
        $inviteUrl = $inviteBaseUrl !== ''
            ? $inviteBaseUrl . '/' . $invite->invite_hash
            : null;

        $notification = new Blank((string) $templateName, [
            'app' => $app,
            'lead' => $this->lead,
            'company' => $company,
            'invite' => $invite,
            'inviteHash' => $invite->invite_hash,
            'inviteUrl' => $inviteUrl,
            'corporateLegalName' => $this->lead->get('legal_name'),
            'contactName' => $this->lead->get('contact_name') ?? $this->lead->firstname,
        ], ['mail'], $this->lead);
        $notification->setSubject('Bienvenido al portal corporativo');

        try {
            LaravelNotification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            // Email failures don't fail approval — Company/Invite are the source of truth.
            report($e);
        }
    }
}
