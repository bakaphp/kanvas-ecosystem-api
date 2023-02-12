<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Branches\Actions\UpdateCompanyBranchActions;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;

final class UpdateCompaniesBranch
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver
        $dto = CompaniesBranchPutData::fromArray($request['input']);
        $action = new  UpdateCompanyBranchActions(Auth::user(), $dto);
        return $action->execute($request['id']);
    }
}
