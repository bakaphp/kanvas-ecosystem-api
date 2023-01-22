<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;

class DeleteCompanyBranchActions
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user
    ) {
    }

    /**
     * Invoke function.
     *
     * @return Companies
     */
    public function execute(int $companyBranchId) : CompaniesBranches
    {
        $companyBranch = CompaniesBranches::getById($companyBranchId);
        $company = $companyBranch->company()->first();

        $company->isOwner($this->user);
        CompaniesRepository::userAssociatedToCompanyAndBranch($company, $companyBranch, $this->user);

        $companyBranch->softDelete();

        return $companyBranch;
    }
}
