<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;

final class CreateCompany
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver
        $request['input']['users_id'] = Auth::user()->getKey();
        $dto = CompaniesPostData::fromArray($request['input']);
        $action = new  CreateCompaniesAction($dto);
        return $action->execute();
    }
}
