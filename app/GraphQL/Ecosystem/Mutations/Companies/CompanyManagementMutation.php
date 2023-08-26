<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Actions\DeleteCompaniesAction;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class CompanyManagementMutation
{
    /**
     * createCompany
     */
    public function createCompany(mixed $root, array $request): Companies
    {
        $request['input']['users_id'] = Auth::user()->getKey();
        $dto = CompaniesPostData::fromArray($request['input']);
        $action = new  CreateCompaniesAction($dto);

        return $action->execute();
    }

    /**
     * updateCompany
     */
    public function updateCompany(mixed $root, array $request): Companies
    {
        $dto = CompaniesPutData::fromArray($request['input']);
        $action = new UpdateCompaniesAction(Auth::user(), $dto);

        return $action->execute((int) $request['id']);
    }

    /**
     * deleteCompany
     */
    public function deleteCompany(mixed $root, array $request): bool
    {
        /**
         * @todo only super admin can do this
         */
        $companyDelete = new DeleteCompaniesAction(Auth::user());
        $companyDelete->execute((int) $request['id']);

        return true;
    }

    public function addUserToCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
        $company = Companies::getById($request['id']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            CompaniesBranches::getGlobalBranch()
        );

        return true;
    }

    public function removeUserFromCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
        $company = Companies::getById($request['id']);

        if ($company->users_id == $user->getId()) {
            throw new AuthenticationException('You can not remove yourself from the company');
        }

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            CompaniesBranches::getGlobalBranch()
        )->delete();

        return true;
    }
}
