<?php

namespace Kanvas\Users\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class AddUserCompanyAction
{
    public function __construct(
        protected Users $authUser,
        protected Companies $company,
        protected AppInterface $app,
        protected CompaniesBranches $branch
    ) {
    }

    public function execute(Collection $users, int|string|null $rolId = null): bool
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->company,
            $this->authUser
        );

        $this->company->hasCompanyPermission($this->authUser);

        $companyDefaultBranch = $this->company->defaultBranch()->first();

        //this happens if they we dont get a branch for via header for the current company (frontend needs to fix)
        if ($this->branch->companies_id != $this->company->getId() && $companyDefaultBranch) {
            $this->branch = $companyDefaultBranch;
        }

        DB::transaction(function () use ($users, $rolId) {
            foreach ($users as $user) {
                $this->company->associateUser(
                    $user,
                    StateEnums::YES->getValue(),
                    CompaniesBranches::getGlobalBranch(),
                    (int) ($rolId ?? null)
                );

                if (is_object($this->branch)) {
                    $this->company->associateUser(
                        $user,
                        StateEnums::YES->getValue(),
                        $this->branch,
                        (int) ($rolId ?? null)
                    );
                }

                $this->company->associateUserApp(
                    $user,
                    $this->app,
                    StateEnums::YES->getValue(),
                    (int) ($rolId ?? null)
                );

                //@todo this is a legacy role and should be removed
                $assignLegacyRole = new AssignRoleAction(
                    $user,
                    $this->company,
                    $this->app
                );
                $assignLegacyRole->execute('Admins');
            }
        });

        return true;
    }
}
