<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

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
        $this->company->associateUser(
            $this->user,
            StateEnums::YES->getValue(),
            $this->branch
        )->delete();

        $this->company->associateUser(
            $this->user,
            StateEnums::YES->getValue(),
            CompaniesBranches::getGlobalBranch()
        )->delete();

        if ($this->user->get(Companies::cacheKey())) {
            $this->user->del(Companies::cacheKey());
        }

        if ($this->user->get($this->company->branchCacheKey())) {
            $this->user->del($this->company->branchCacheKey());
        }
    }
}
