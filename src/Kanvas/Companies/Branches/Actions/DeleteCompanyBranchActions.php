<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
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
