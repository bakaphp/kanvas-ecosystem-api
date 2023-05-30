<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

class RemoveCompanyAction
{
    public Users $user;
    public Companies $company;
    public CompaniesBranches $branch;
    public DefaultRoles $role;
    public Apps $app;

    public function __construct(
        Users $user,
        CompaniesBranches $branch,
        ?Apps $app = null
    ) {
        $this->user = $user;
        $this->company = $branch->company()->first();
        $this->branch = $branch;
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * execute
     */
    public function execute(): void
    {
        $this->company->associateUserApp(
            $this->user,
            $this->app,
            StateEnums::YES->getValue(),
        )->delete();
        
        $otherAssociation = UsersAssociatedApps::where('users_id', $this->user->getId())
            ->where('apps_id', $this->app->getId())
            ->get();

        if ($otherAssociation->count()) {
            $newPrimaryCompany = $otherAssociation->first();
            $newCompany = Companies::getById($newPrimaryCompany->companies_id);
            $this->user->set(Companies::cacheKey(), $newCompany->getId());
        }

        $stillHasAccessToThisCompany = UsersAssociatedApps::where('users_id', $this->user->getId())
            ->where('companies_id', $this->company->getId())
            ->get();

        if (!$stillHasAccessToThisCompany->count()) {
            $this->company->associateUser(
                $this->user,
                StateEnums::YES->getValue(),
                $this->branch
            )->delete();
        }
    }
}
