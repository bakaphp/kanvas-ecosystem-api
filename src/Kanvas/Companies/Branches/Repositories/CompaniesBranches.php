<?php
declare(strict_types=1);
namespace Kanvas\Companies\Branches\Repositories;

use Kanvas\Companies\Models\CompaniesBranches as CompaniesBranchesModel;

class CompaniesBranches
{
    /**
     * getById
     *
     * @param  int $id
     * @return CompaniesBranchesModel
     */
    public static function getById(int $id, int $userId = 0): CompaniesBranchesModel
    {
        $userId = $userId ?? auth()->user()->id;
        return CompaniesBranchesModel::join('users_associated_company', 'users_associated_company.companies_branches_id', '=', 'companies_branches.id')
            ->where('users_associated_company.users_id', $userId)
            ->findOrFail($id);
    }
}
