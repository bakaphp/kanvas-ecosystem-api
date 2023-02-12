<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Branches\Actions\DeleteCompanyBranchActions;

final class DeleteCompaniesBranch
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $companyBranchDelete = new DeleteCompanyBranchActions(Auth::user());
        $branch = $companyBranchDelete->execute($request['id']);

        return 'Successfully Delete Company Branch : ' . $branch->name;
    }
}
