<?php

declare(strict_types=1);

namespace Kanvas\Companies\Branches\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class DeleteCompanyBranchActions
{
    public function __construct(
        protected Users $user,
        protected AppInterface $app
    ) {
    }

    public function execute(int $companyBranchId): CompaniesBranches
    {
        $companyBranch = CompaniesBranches::getById($companyBranchId);
        $company = $companyBranch->company()->first();

        $company->hasCompanyPermission($this->user, $this->app);

        if (! $company->isOwner($this->user) && ! $this->user->isOwnerOf($this->app)) {
            throw new AuthorizationException('Only the owner of the company can delete a branch.');
        }

        $companyBranch->softDelete();

        return $companyBranch;
    }
}
