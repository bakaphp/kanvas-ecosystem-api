<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class SwitchCompanyBranchAction
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

    /**
     * execute
     *
     * @return bool
     */
    public function execute(): bool
    {
        $branch = CompaniesBranches::getById($this->companyBranchId);
        $company = $branch->company()->firstOrFail();
        UsersRepository::belongsToCompanyBranch($this->user, $company, $branch);

        $this->user->default_company = $company->getId();
        $this->user->default_company_branch = $branch->getId();
        $this->user->saveOrFail();

        $this->user->set(Companies::cacheKey(), $branch->company->getId());
        $this->user->set($company->branchCacheKey(), $branch->getId());

        return true;
    }
}
