<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Jobs\MigrateCorporateUserVariantsJob;
use Kanvas\Connectors\Movipass\Workflows\Activities\AutoApproveCorporateLeadActivity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Models\Users;

class EnableCorporateModeAction
{
    public function __construct(
        protected readonly Users $user,
        protected readonly AppInterface $app,
        protected readonly array $fields,
    ) {
    }

    public function execute(): Companies
    {
        $validationError = new ValidateCorporateFieldsAction($this->fields)->execute();

        if ($validationError !== null) {
            throw new ValidationException($validationError);
        }

        if ((bool) $this->user->get('is_corporate')) {
            throw new ValidationException('User is already corporate');
        }

        $sourceCompanyId = $this->user->getCurrentCompany()->getId();
        $appsModel = $this->app instanceof Apps ? $this->app : app(Apps::class);

        return DB::connection('ecosystem')->transaction(function () use ($sourceCompanyId, $appsModel) {
            $company = $this->createCorporateCompany();
            $this->setCompanyFields($company);
            $this->setUserFields();
            $this->associateUserAsAdmin($company, $appsModel);
            // Same onboarding the registration/lead-accept path runs — provisions the
            // company's default inventory (region + warehouse) via OnBoardingJob.
            new SetupService()->onBoarding($this->user, $appsModel, $company);
            $this->switchToCorporateCompany($company);

            dispatch(new MigrateCorporateUserVariantsJob(
                app: $appsModel,
                userId: $this->user->getId(),
                sourceCompanyId: $sourceCompanyId,
                targetCompanyId: $company->getId(),
            ));

            return $company;
        });
    }

    private function createCorporateCompany(): Companies
    {
        $name = trim((string) ($this->fields['commercial_name']
            ?: $this->fields['legal_name']
            ?: $this->user->displayname . ' Corporate'));

        return new CreateCompaniesAction(
            new CompanyData(
                user: $this->user,
                name: $name,
                email: trim((string) ($this->fields['contact_email'] ?? $this->user->email)),
                phone: trim((string) ($this->fields['contact_phone'] ?? '')),
            ),
        )->execute();
    }

    private function setCompanyFields(Companies $company): void
    {
        $company->set('is_corporate', true);

        foreach (AutoApproveCorporateLeadActivity::CORPORATE_COMPANY_FIELDS as $key) {
            $value = $this->fields[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $company->set($key, $value);
        }

        if (! empty($this->fields['region_id'])) {
            $region = Regions::getByIdFromCompanyAppOrGlobal(
                (int) $this->fields['region_id'],
                $this->user->getCurrentCompany(),
                $this->app,
            );
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $region->getId());
        } elseif (app()->bound(Regions::class)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, app(Regions::class)->getId());
        }
    }

    private function setUserFields(): void
    {
        $this->user->set('is_corporate', true);

        foreach (AutoApproveCorporateLeadActivity::CORPORATE_USER_FIELDS as $key) {
            if ($key === 'is_corporate') {
                continue;
            }
            $value = $this->fields[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $this->user->set($key, $value);
        }
    }

    private function associateUserAsAdmin(Companies $company, Apps $app): void
    {
        $branch = $company->branch()->firstOrFail();
        $adminRole = RolesRepository::getByNameFromApp(RolesEnums::ADMIN->value, $app);

        new AssignCompanyAction(
            user: $this->user,
            branch: $branch,
            role: $adminRole,
            app: $app,
        )->execute();

        new AssignRoleAction($this->user, $adminRole)->execute();
    }

    private function switchToCorporateCompany(Companies $company): void
    {
        $branch = $company->branch()->firstOrFail();

        new SwitchCompanyBranchAction($this->user, $branch->getId())->execute();
    }
}
