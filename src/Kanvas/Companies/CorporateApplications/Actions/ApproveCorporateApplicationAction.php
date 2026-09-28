<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Concerns\ResolvesUpgradeTarget;
use Kanvas\Companies\CorporateApplications\Concerns\ReviewsApplication;
use Kanvas\Companies\CorporateApplications\Concerns\SendsApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Workflow\Enums\WorkflowEnum;

class ApproveCorporateApplicationAction
{
    use ResolvesUpgradeTarget;
    use ReviewsApplication;
    use SendsApplicationEmail;

    public const string DEFAULT_TEMPLATE = 'corporate-welcome';

    public function __construct(
        protected readonly Model $application,
        protected readonly Apps $app,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        $this->assertNotDecided($this->application, CorporateApplicationStatusEnum::APPROVED);

        $missing = Field::missing(Field::requiredFor($this->application->receiver), $this->read(...));

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
        $owner = $this->application->receiver->user;
        $company = $this->findOrCreateCompany($owner);

        $company->set('is_corporate', true);
        Field::copy(Field::companyFieldsFor($this->application->receiver), $this->read(...), $company);

        new SetupService()->onBoarding($owner, $this->app, $company);

        $invite = $this->findOrCreateInvite($company, $owner);

        Field::COMPANY_ID->writeTo($this->application, (string) $company->getId());
        Field::INVITE_HASH->writeTo($this->application, $invite->invite_hash);
        $this->stampReview($this->application, CorporateApplicationStatusEnum::APPROVED, $this->reviewedBy);

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
        $target = $this->upgradeTarget($this->application, $this->app);

        if ($target === null) {
            throw new ValidationException('Cannot approve: the upgrade target does not belong to this app.');
        }

        $target->company->set('is_corporate', true);
        $target->user->set('is_corporate', true);

        new SwitchCompanyBranchAction($target->user, $target->company->branch()->firstOrFail()->getId())->execute();

        $this->stampReview($this->application, CorporateApplicationStatusEnum::APPROVED, $this->reviewedBy);

        return [
            'application' => $this->application->getId(),
            'status' => CorporateApplicationStatusEnum::APPROVED->value,
            'company_id' => $target->company->getId(),
            'invite_hash' => null,
        ];
    }

    private function findOrCreateCompany(Users $owner): Companies
    {
        $existingCompanyId = Field::COMPANY_ID->readFrom($this->application);

        if ($existingCompanyId) {
            return Companies::getById((int) $existingCompanyId);
        }

        $fallbackName = Str::trimToNull((string) $this->application->title) ?? 'Corporate Account';

        return new CreateApplicationCompanyAction($owner, $this->read(...), $fallbackName)->execute();
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
            'email' => trim((string) $this->read('contact_email')),
            'firstname' => trim((string) $this->read('contact_name')),
            'lastname' => trim((string) ($this->application->lastname ?: '')),
            'description' => 'Corporate self-signup',
        ]);
        $invite->saveOrFail();

        $invite->set('is_corporate', true);
        Field::copy(Field::userFieldsFor($this->application->receiver), $this->read(...), $invite);

        return $invite;
    }

    private function read(string $key): mixed
    {
        return Field::readApplication($this->application, $key);
    }

    private function sendWelcomeEmail(Companies $company, UsersInvite $invite): void
    {
        $inviteBaseUrl = rtrim((string) Setting::INVITE_LINK_BASE->readFrom($this->app, ''), '/');

        $this->sendApplicationEmail(
            $this->app,
            (string) Setting::WELCOME_TEMPLATE->readFrom($this->app, self::DEFAULT_TEMPLATE),
            'Bienvenido al portal corporativo',
            [
                'lead' => $this->application,
                'company' => $company,
                'invite' => $invite,
                'inviteHash' => $invite->invite_hash,
                'inviteUrl' => $inviteBaseUrl !== '' ? $inviteBaseUrl . '/' . $invite->invite_hash : null,
                'corporateLegalName' => $this->application->get('legal_name'),
                'contactName' => Field::contactName($this->application),
            ],
            (string) $this->application->email,
            $this->application,
        );
    }
}
