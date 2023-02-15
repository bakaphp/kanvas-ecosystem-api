<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Actions\DeleteCompaniesAction;

final class DeleteCompany
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
        $companyDelete = new DeleteCompaniesAction(Auth::user());
        $companyDelete->execute($request['id']);

        return true;
    }
}
