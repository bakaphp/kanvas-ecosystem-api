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
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\DataTransferObject\Company as CompanyData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\AssignCompanyAction;
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

        if ($this->hasPendingRequest()) {
            throw new ValidationException('You already have a corporate request under review.');
        }

        $sourceCompanyId = $this->user->getCurrentCompany()->getId();
        $appsModel = $this->app instanceof Apps ? $this->app : app(Apps::class);
        $receiver = $this->corporateReceiver($appsModel);

        $company = DB::connection('ecosystem')->transaction(function () use ($appsModel) {
            $company = $this->createCorporateCompany();
            $this->setCompanyFields($company);
            $this->setUserFields();
            $this->associateUserAsAdmin($company, $appsModel);
            new SetupService()->onBoarding($this->user, $appsModel, $company);

            return $company;
        });

        $this->fileForReview($receiver, $company, $sourceCompanyId);

        return $company;
    }

    private function fileForReview(LeadReceiver $receiver, Companies $company, int $sourceCompanyId): void
    {
        $lead = new Lead();
        $lead->fill([
            'apps_id' => $receiver->apps_id,
            'users_id' => $receiver->users_id,
            'companies_id' => $receiver->companies_id,
            'companies_branches_id' => $receiver->companies_branches_id,
            'leads_receivers_id' => $receiver->getId(),
            'leads_owner_id' => $receiver->users_id,
            'title' => trim((string) (($this->fields['commercial_name'] ?? null) ?: ($this->fields['legal_name'] ?? ''))),
            'firstname' => (string) ($this->fields['contact_name'] ?? $this->user->firstname),
            'lastname' => (string) $this->user->lastname,
            'email' => trim((string) ($this->fields['contact_email'] ?? $this->user->email)),
            'phone' => trim((string) ($this->fields['contact_phone'] ?? '')),
        ]);
        $lead->saveOrFail();

        Field::copy([...Field::COMPANY_FIELDS, ...Field::USER_PROFILE_FIELDS], $this->field(...), $lead);

        Field::STATUS->writeTo($lead, CorporateApplicationStatusEnum::PENDING->value);
        Field::COMPANY_ID->writeTo($lead, (string) $company->getId());
        Field::UPGRADE_USER_ID->writeTo($lead, (string) $this->user->getId());
        Field::UPGRADE_SOURCE_COMPANY_ID->writeTo($lead, (string) $sourceCompanyId);
    }

    private function hasPendingRequest(): bool
    {
        return Lead::query()
            ->fromApp($this->app)
            ->whereIn('id', function ($q) {
                $q->select('entity_id')
                    ->from(DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields')
                    ->where('model_name', Lead::class)
                    ->where('name', Field::UPGRADE_USER_ID->value)
                    ->where('value', (string) $this->user->getId())
                    ->where('is_deleted', 0);
            })
            ->whereIn('id', function ($q) {
                $q->select('entity_id')
                    ->from(DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields')
                    ->where('model_name', Lead::class)
                    ->where('name', Field::STATUS->value)
                    ->where('value', CorporateApplicationStatusEnum::PENDING->value)
                    ->where('is_deleted', 0);
            })
            ->notDeleted()
            ->exists();
    }

    private function corporateReceiver(Apps $app): LeadReceiver
    {
        $receiverId = Setting::RECEIVER_ID->readFrom($app);

        if (empty($receiverId)) {
            throw new ValidationException(
                'Corporate onboarding is not configured for this app (missing corporate receiver).'
            );
        }

        return LeadReceiver::getById((int) $receiverId, $app);
    }

    private function createCorporateCompany(): Companies
    {
        $name = trim((string) (($this->fields['commercial_name'] ?? null)
            ?: ($this->fields['legal_name'] ?? null)
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
        Field::copy(Field::COMPANY_FIELDS, $this->field(...), $company);

        if (! empty($this->fields['region_id'])) {
            $region = Regions::getByIdFromCompanyAppOrGlobal((int) $this->fields['region_id'], $this->user->getCurrentCompany(), $this->app);
            new SetCompanyRegionAction($company, $region->getId())->execute();
        } elseif (app()->bound(Regions::class)) {
            new SetCompanyRegionAction($company, app(Regions::class)->getId())->execute();
        }
    }

    private function setUserFields(): void
    {
        Field::copy(Field::USER_PROFILE_FIELDS, $this->field(...), $this->user);
    }

    private function field(string $key): mixed
    {
        return $this->fields[$key] ?? null;
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
}
