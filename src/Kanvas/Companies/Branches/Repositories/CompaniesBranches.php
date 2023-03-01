<?php
declare(strict_types=1);
namespace Kanvas\Companies\Branches\Repositories;

use Kanvas\Companies\Branches\Models\CompaniesBranches as CompaniesBranchesModel;

class CompaniesBranches
{
    /**
     * getById
     *
     * @param  int $id
     * @return CompaniesBranchesModel
     */
    public function getById(int $id): CompaniesBranchesModel
    {
        return CompaniesBranchesModel::join('users_associated_company', 'users_associated_company.companies_branches_id', '=', 'companies_branches.id')
            ->where('users_associated_company.users_id', $this->user->getId())
            ->findOrFail($id);
    }
}
