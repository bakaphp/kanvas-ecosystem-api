<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

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
    public function execute(int $companyBranchId): CompaniesBranches
    {
        $companyBranch = CompaniesBranches::getById($companyBranchId);
        $company = $companyBranch->company()->first();

        $company->hasCompanyPermission($this->user);

        if (! $company->isOwner($this->user) && ! $this->user->isOwnerOf(app(Apps::class))) {
            throw new AuthorizationException('Only the owner of the company can delete a branch.');
        }

        $companyBranch->softDelete();

        return $companyBranch;
    }
}
