<?php

namespace App\GraphQL\Mutations;

use Kanvas\Companies\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Companies\Models\Companies;

final class CreateCompany
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request) : Companies
    {
        $data = CompaniesPostData::fromArray($request);
        $company = new CreateCompaniesAction($data);
        return $company->execute();
    }
}
