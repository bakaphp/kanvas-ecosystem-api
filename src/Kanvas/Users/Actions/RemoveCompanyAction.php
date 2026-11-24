<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\AccessControlList\Actions\ForbiddenRolesUsersAction;
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

        new ReassignDefaultCompanyAction($this->user, $this->app, $this->company)->execute();

        $stillHasAccessToThisCompany = UsersAssociatedApps::where('users_id', $this->user->getId())
            ->where('companies_id', $this->company->getId())
            ->get();

        if (! $stillHasAccessToThisCompany->count()) {
            $this->company->associateUser(
                $this->user,
                StateEnums::YES->getValue(),
                $this->branch
            )->delete();
        }
        (new ForbiddenRolesUsersAction(
            $this->app,
            $this->user,
            $this->company
        ))->execute();
    }
}
