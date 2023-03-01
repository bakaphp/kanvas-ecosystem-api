<?php
declare(strict_types=1);
namespace Kanvas\Users\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Branches\Repositories\CompaniesBranches as CompaniesBranchesRepository;

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
     * @return Users
     */
    public function execute(): Users
    {
        if ($branch = CompaniesBranchesRepository::getById((int)$this->companyBranchId, $this->user->id)) {
            if ($branch->company) {
                $this->user->default_company = $branch->company->id;
                $this->user->default_company_branch = $branch->id;
                $this->user->saveOrFail();
                $this->user->set(Companies::cacheKey(), $branch->company->id);
                $this->user->set($branch->company->branchCacheKey(), $branch->id);
                return $this->user;
            }
        }
        return $this->user;
    }
}
