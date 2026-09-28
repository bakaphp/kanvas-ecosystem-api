<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Actions\CreateApplicationCompanyAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
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
        protected readonly Apps $app,
        protected readonly array $fields,
    ) {
    }

    public function execute(): Companies
    {
        $receiver = $this->corporateReceiver($this->app);
        $missing = Field::missing(Field::requiredFor($receiver), $this->field(...));

        if ($missing !== []) {
            throw new ValidationException('Missing required fields: ' . implode(', ', $missing));
        }

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

        $company = DB::connection('ecosystem')->transaction(function () use ($receiver) {
            $company = $this->createCorporateCompany();
            $this->setCompanyFields($company, $receiver);
            Field::copy(Field::userFieldsFor($receiver), $this->field(...), $this->user);
            $this->associateUserAsAdmin($company);
            new SetupService()->onBoarding($this->user, $this->app, $company);

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

        Field::copy(
            [
            ...Field::companyFieldsFor($receiver),
            ...Field::userFieldsFor($receiver),
            ],
            $this->field(...),
            $lead
        );

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
        $fallbackName = $this->user->displayname . ' Corporate';

        return new CreateApplicationCompanyAction($this->user, $this->field(...), $fallbackName)->execute();
    }

    private function setCompanyFields(Companies $company, LeadReceiver $receiver): void
    {
        Field::copy(Field::companyFieldsFor($receiver), $this->field(...), $company);

        if (! empty($this->fields['region_id'])) {
            $region = Regions::getByIdFromCompanyAppOrGlobal((int) $this->fields['region_id'], $this->user->getCurrentCompany(), $this->app);
            new SetCompanyRegionAction($company, $region->getId())->execute();
        } elseif (app()->bound(Regions::class)) {
            new SetCompanyRegionAction($company, app(Regions::class)->getId())->execute();
        }
    }

    private function field(string $key): mixed
    {
        return $this->fields[$key] ?? null;
    }

    private function associateUserAsAdmin(Companies $company): void
    {
        $branch = $company->branch()->firstOrFail();
        $adminRole = RolesRepository::getByNameFromApp(RolesEnums::ADMIN->value, $this->app);

        new AssignCompanyAction(
            user: $this->user,
            branch: $branch,
            role: $adminRole,
            app: $this->app,
        )->execute();

        new AssignRoleAction($this->user, $adminRole)->execute();
    }
}
