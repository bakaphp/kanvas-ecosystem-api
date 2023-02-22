<?php
declare(strict_types=1);
namespace Kanvas\Users\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Models\Companies;

class SwitchCompanyAction
{
    /**
     * __construct
     * @param Users $user
     * @param int $companyId
     * @return void
     */
    public function __construct(
        protected Users $user,
        protected int $companyBranchId
    ) {
    }

    public function handle(): Users
    {
        if ($branch = CompaniesBranches::findFirst($this->companyId)) {
            if ($branch->company) {
                $this->user->default_company = $branch->company->id;
                $this->user->default_company_branch = $branch->id;
                $this->user->saveOrFail();
                $this->user->set(Companies::cacheKey(), $branch->company->id);
                $this->user->set($branch->company->branchCacheKey(), $branch->id);
            }
        }
    }
}
