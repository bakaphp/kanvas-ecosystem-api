<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Branches\Actions\CreateCompanyBranchActions;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;

final class CreateCompaniesBranch
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver
        $request['input']['users_id'] = Auth::user()->getKey();
        $dto = CompaniesBranchPostData::fromArray($request['input']);
        $action = new  CreateCompanyBranchActions(Auth::user(), $dto);
        return $action->execute();
    }
}
