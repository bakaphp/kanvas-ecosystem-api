<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;

final class UpdateCompany
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver\
        $dto = CompaniesPutData::fromArray($request['input']);
        $action = new UpdateCompaniesAction(Auth::user(), $dto);
        return $action->execute($request['id']);
    }
}
